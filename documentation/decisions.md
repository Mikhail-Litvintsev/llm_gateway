# Architectural Decision Records

Short records of non-obvious choices in LLM Gateway. Each entry explains the context, the decision, its consequences and the condition that would make us revisit it.

Format inspired by Michael Nygard's ADR template. Dates are in ISO-8601.

## Table of contents

- [ADR-001: Claude-only gateway](#adr-001-claude-only-gateway)
- [ADR-002: No Horizon](#adr-002-no-horizon)
- [ADR-003: Raw DB for hot-path inserts](#adr-003-raw-db-for-hot-path-inserts)
- [ADR-004: State-machine webhook retry instead of queue retry](#adr-004-state-machine-webhook-retry-instead-of-queue-retry)
- [ADR-005: No idempotency-key for Anthropic Messages API](#adr-005-no-idempotency-key-for-anthropic-messages-api)
- [ADR-006: ENUM in `requests.endpoint`](#adr-006-enum-in-requestsendpoint)
- [ADR-007: No OpenAPI](#adr-007-no-openapi)
- [ADR-008: p95 overhead measurement, not full-response latency](#adr-008-p95-overhead-measurement-not-full-response-latency)
- [ADR-009: CORS restrictive by default](#adr-009-cors-restrictive-by-default)
- [ADR-010: `async_pending.payload_for_anthropic` stored as longText](#adr-010-async_pendingpayload_for_anthropic-stored-as-longtext)
- [Future ADR candidates](#future-adr-candidates)

---

## ADR-001: Claude-only gateway

- **Status:** Accepted (2026-04-25)
- **Context:** A multi-provider abstraction is a common default for an LLM gateway, but the value-add features we rely on — prompt caching, extended thinking, MCP connector, skills, server-side tools, file attachments — are provider-specific. Exposing them through a neutral contract turns the gateway into a lowest-common-denominator wrapper that hides 60–80% of the platform's surface. Alternatives considered: (a) multi-provider adapter layer, (b) per-provider gateways side by side, (c) single-provider gateway with honest pass-through.
- **Decision:** Build a single-provider gateway for Claude. The request/response body is a byte-for-byte pass-through of the Anthropic Messages API; gateway metadata goes into `X-Gateway-*` headers only.
- **Consequences:** Clients target the Anthropic schema directly — their code is portable. Gateway retains full feature parity with Anthropic as it evolves. When another provider is needed, option (b) applies: a sibling gateway, not an abstraction. The downside: zero provider-failover out of the box — an Anthropic outage surfaces to clients with no retry to a second vendor.
- **Revisit trigger:** A second provider becomes a business requirement AND its feature surface overlaps with Claude's by ≥80% (so abstraction no longer drops value).
- **Related:** `README.md` "Why Claude-only", [ADR-007](#adr-007-no-openapi).

---

## ADR-002: No Horizon

- **Status:** Accepted (2026-04-25)
- **Context:** Laravel Horizon is the default queue dashboard for production Laravel projects. For this project, queue topology is narrow — one worker, four named queues (high, default, low, batch), <100 jobs/s under expected load. Horizon's benefits (dashboard, auto-balancing, job tagging) would come at the cost of another dependency, another web UI, and a long-running Horizon supervisor process inside the compose stack.
- **Decision:** Do not add Horizon. Queue observability is covered by the `failed_jobs` table (standard Laravel, migration `2026_03_18_162451_create_failed_jobs_table.php`), the `queue:failed` Artisan command, and a local `queue:monitor` command (`app/Console/Commands/MonitorQueue.php`, signature `queue:monitor`) that prints queue depths from Redis and `failed_jobs` count.
- **Consequences:** Zero extra runtime surface. No web dashboard — ops read CLI output. For pet-scope this is a good trade.
- **Revisit trigger:** Worker count >10, OR multi-tenant failed-job triage becomes a daily activity, OR SLAs require a visual timeline of retries. Adding Horizon is ~2 hours from this point.
- **Related:** `app/Console/Commands/MonitorQueue.php`, Phase 3.5 of the senior-review task.

---

## ADR-003: Raw DB for hot-path inserts

- **Status:** Accepted (2026-04-25)
- **Context:** Every gateway request produces at least three DB writes: `requests` (state + metadata), `request_usage` (billing breakdown), `request_raw` (masked payloads for audit). These run inside a single transaction on the sync path and on the async finaliser path. Eloquent adds observer dispatch, event broadcasting, attribute casting and serialization — valuable for read-mostly domain models, pure overhead for append-only hot-path writes.
- **Decision:** `app/Components/Logging/Logging.php` uses `DB::table()` with bound parameters for `record()` and `updateAsyncRecord()`. Controllers, jobs and actions — converted to Eloquent/Repositories in Phase 5 — keep the boundary: read-side through models/Repositories, write-side for audit/billing through raw DB.
- **Consequences:** The hot-path insert stack has no Eloquent overhead. The cost is that observers/events cannot hook into `requests` inserts directly; anything cross-cutting (audit, outbox) has to live at the `Logging` call-site.
- **Revisit trigger:** A new feature requires Eloquent events on write (audit-log observer, outbox-relay trigger). At that point `Logging` is the first thing to migrate to Eloquent.
- **Related:** `app/Components/Logging/Logging.php`, Phase 5.7.

---

## ADR-004: State-machine webhook retry instead of queue retry

- **Status:** Accepted (2026-04-25)
- **Context:** Webhook delivery is multi-step and long-running: up to 10 attempts across up to ~90 minutes with exponential backoff (10s → 20s → … → 3600s cap). Queue-level retries (`$tries` + `backoff()`) expose one integer counter — the same counter that `$job->release($delay)` increments — and offer no way to persist state (current attempt, next-attempt-at, exhaustion) for inspection between pickups.
- **Decision:** `DeliverWebhook` uses `$tries = 1` deliberately. The job is a single delivery attempt; retry state lives in `async_pending.{callback_attempts, next_attempt_at, status}`. A scheduler (`RetryFailedWebhooks`, `routes/console.php`) runs every minute and dispatches the next `DeliverWebhook` for each row whose `next_attempt_at <= now()`.
- **Consequences:** Retry state is queryable and survives worker restarts. The grace-period rotation for the signing secret works naturally because the scheduler always re-reads the row. The downside: delivery bias is ≤1 minute (scheduler resolution) — not meaningful for webhooks that already have multi-second backoff.
- **Revisit trigger:** Migration to a workflow engine (Temporal / Cadence) — then the state machine moves out of the DB into the workflow definition.
- **Related:** `app/Jobs/DeliverWebhook.php`, `app/Jobs/Scheduled/RetryFailedWebhooks.php`, Phase 3 "Важно про два разных паттерна retries".

---

## ADR-005: No idempotency-key for Anthropic Messages API

- **Status:** Accepted (2026-04-25)
- **Context:** As of 2026-04, Anthropic Messages API does not support an idempotency-key. A job retry after a successful upstream call but before the local DB write would double-charge tokens. We need idempotency on our side.
- **Decision:** `ProcessAsyncMessage::handle` performs a pre-call check: `EXISTS (SELECT 1 FROM request_raw WHERE request_id=? AND response_payload IS NOT NULL)`. If present — skip the Claude call and finalise from the stored body. `Logging::updateAsyncRecord` writes `request_raw.response_payload` in its own transaction first (before `requests`/`request_usage`), making "call succeeded" observable as a DB row.
- **Consequences:** Retry matrix:
  - (a) Claude success → retry → skip Claude, finalise idempotently.
  - (b) Claude 5xx transient → retry → Claude called again (no `response_payload` row yet).
  - (c) Claude 4xx (invalid) → no retry.
  - (d) Claude success → `request_usage` insert fails → retry → Claude skipped (row exists), finalise retries.

  Residual race window: job dies between the Anthropic HTTP response and the first `request_raw` insert (milliseconds). This cannot be eliminated without upstream support.
- **Revisit trigger:** Anthropic adds an idempotency-key header — then `ProcessAsyncMessage` generates a per-request key and the pre-check is no longer necessary.
- **Related:** `app/Components/Logging/Logging.php`, `app/Jobs/ProcessAsyncMessage.php`, Phase 3.2.

---

## ADR-006: ENUM in `requests.endpoint`

- **Status:** Accepted (2026-04-25)
- **Context:** `database/migrations/..._create_requests_table.php` declares `endpoint` and `mode` as MySQL `ENUM` columns. Current snapshot: `endpoint ENUM('messages', 'batch_item', 'count_tokens', 'session_message')`, `mode ENUM('sync', 'sync_stream', 'async_callback', 'batch')`. Adding a value requires an `ALTER TABLE`, which is blocking on InnoDB for large tables.
- **Decision:** Keep ENUM for pet-scope. No `endpoints` / `modes` lookup table.
- **Consequences:** Adding a new endpoint type requires a schema migration that must be coordinated with a deploy. Schema stays compact and human-readable; no extra joins. The migration path, if ever needed: shadow-column of the target type → backfill → swap → drop ENUM.
- **Revisit trigger:** Any of: (a) adding >1 endpoint type per quarter, (b) `requests` table crosses 100M rows, (c) online-DDL tooling (gh-ost / pt-online-schema-change) becomes part of the deploy pipeline.
- **Related:** `database/migrations/*create_requests_table.php`.

---

## ADR-007: No OpenAPI

- **Status:** Accepted (2026-04-25)
- **Context:** LLM Gateway pass-throughs the Anthropic Messages API. Generating an OpenAPI spec would produce ~95% duplication of Anthropic's own spec, and keeping the two in sync would be a perpetual source of drift.
- **Decision:** Do not generate a full OpenAPI document. Instead: reference the Anthropic Messages API from the README and maintain a `Deviations from Anthropic Messages API` section in `client_integration_guide.md` that enumerates gateway-specific differences (auth scheme, `X-Gateway-*` headers, additional endpoints, webhook envelope, error-type extensions).
- **Consequences:** Integrators use Anthropic's SDKs with `base_url` pointing at the gateway and read a short deviations section for the gateway-specific bits. No tooling to regenerate or validate OpenAPI — one less moving part.
- **Revisit trigger:** Typed SDK generation (Go, Rust, Kotlin) for clients becomes a requirement, and hand-rolled clients are not acceptable.
- **Related:** [ADR-001](#adr-001-claude-only-gateway), `documentation/client_integration_guide.md` section "Deviations from Anthropic Messages API".

---

## ADR-008: p95 overhead measurement, not full-response latency

- **Status:** Accepted (2026-04-25)
- **Context:** End-to-end LLM response latency is dominated by Anthropic (typically 1–5+ seconds, sometimes much more under load or with large prompts). Measuring "p95 ≤ 500 ms" for an end-to-end LLM request is not achievable and would be dishonest as a portfolio claim. What the gateway *does* control is the time it spends on its own side: authentication, rate-limit snapshot read, DB writes, payload masking, response assembly.
- **Decision:** The reported metric is **gateway overhead**: the time between request arrival and response emission, excluding the upstream call. Measured with `benchmarks/gateway-overhead.js` (k6) against a deterministic mock-upstream that returns a fixed Anthropic-shaped body. Published numbers: p50/p95/p99 from the steady-state phase.
- **Consequences:** Numbers are reproducible, gateway-attributable, and honest. A reader understands what "fast" means here. Cost: does not speak to the client's wall-clock experience — that is a function of Anthropic load.
- **Revisit trigger:** Infrastructure moves to a region with materially different network properties (cross-continent), OR hot-path logging is re-architected (e.g. async logging via queue) — both warrant a re-measurement.
- **Numbers:** `{{PLACEHOLDER: numbers filled in step_07}}`. Source: [`benchmarks/results.md`](../benchmarks/results.md).
- **Related:** `benchmarks/`, README "Performance" section.

---

## ADR-009: CORS restrictive by default

- **Status:** Accepted (2026-04-25)
- **Context:** LLM Gateway is a server-to-server service. API keys are `gw_live_*` Bearer tokens meant to be stored in backend secret stores, not in browser memory. A permissive CORS policy (`*`) would enable accidental browser-side usage, which leaks keys through the user-agent.
- **Decision:** `config/cors.php` ships with `allowed_origins: []` by default. Opt-in is via `CORS_ALLOWED_ORIGINS` env (comma-separated exact origins). Wildcard `*` is not supported even through config. `supports_credentials: false` — the gateway does not serve cookies.
- **Consequences:** A browser client cannot talk to the gateway out of the box. That is the intended default. When a dashboard or playground needs it, the operator adds its origin to the env explicitly — the decision becomes visible in the deployment config.
- **Revisit trigger:** A first-party browser client (JS dashboard, playground) is introduced and its origin needs to be whitelisted. That does not change the ADR — it just exercises the opt-in path.
- **Related:** `config/cors.php`, `.env.example` (`CORS_ALLOWED_ORIGINS`).

---

## ADR-010: `async_pending.payload_for_anthropic` stored as longText

- **Status:** Accepted (2026-04-25)
- **Context:** Async requests include file attachments (base64-encoded PDFs, images) that can reach ~10 MB per request. The naive storage shape is a `longText` column in `async_pending` that holds the whole payload until the worker picks it up.
- **Decision:** Keep payload in `async_pending.payload_for_anthropic` as `longText` (verified: `database/migrations/*async_pending*`).
- **Consequences:** Migration is trivial, the job just reads its own row. InnoDB row size may bloat for large payloads, off-page storage kicks in for >8 KB values, and the `async_pending` table can grow quickly under heavy file traffic. Acceptable for pet-scope. The production-ready path is well-known: put the payload into object storage (S3 / minio), store only a pointer (`async_pending.payload_storage_key`) in the DB, lazy-load the payload in the worker.
- **Revisit trigger:** Either (a) >1% of async requests carry >1 MB payloads, or (b) `async_pending` on disk exceeds ~10 GB. Migration work is ~1 sprint: storage driver + pointer column + dual-read during rollout.
- **Related:** `database/migrations/*async_pending*`, Phase 7.4 "Open questions".

---

## Future ADR candidates

Questions that are decided pragmatically today but would deserve an ADR if the answer hardens into a policy:

- **Horizon opt-in path.** Captured informally in ADR-002 revisit trigger. If the team switches, write ADR-0NN with the rollout plan.
- **OpenAPI generation.** Same — captured in ADR-007 revisit trigger. A typed-client SDK decision would promote this to its own ADR.
- **ENUM → lookup table migration for `requests.endpoint`.** Captured in ADR-006 revisit trigger. A concrete migration plan (shadow column → backfill → swap) would be the ADR body.
- **Async payload → object storage.** ADR-010 revisit trigger. The first experiment with large files would drive this.
- **Async idempotency key.** If Anthropic ships one, ADR-005 is superseded by a new ADR documenting the client-side key format.
