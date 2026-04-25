# Operational Runbook -- LLM Gateway v4

Target audience: on-call engineers handling incidents.

---

## Table of contents

1. [How to read claude:status](#1-how-to-read-claudestatus)
2. [What to do when claude:status = degraded](#2-what-to-do-when-claudestatus--degraded)
3. [How to trigger claude:resume manually](#3-how-to-trigger-clauderesume-manually)
4. [Debug failed webhook deliveries](#4-debug-failed-webhook-deliveries)
5. [Debug stuck batch](#5-debug-stuck-batch)
6. [How to update model aliases](#6-how-to-update-model-aliases)
7. [How to update pricing](#7-how-to-update-pricing)
8. [How to update beta headers](#8-how-to-update-beta-headers)
9. [How to clean up orphaned files](#9-how-to-clean-up-orphaned-files)
10. [How to extract client data by request_id](#10-how-to-extract-client-data-by-request_id)
11. [Drop legacy tables safeguard](#11-drop-legacy-tables-safeguard)
12. [Streaming pool monitoring](#12-streaming-pool-monitoring)
13. [Escalation: when to file with Anthropic support](#13-escalation-when-to-file-with-anthropic-support)
14. [Adjust client rate limit](#adjust-client-rate-limit)
15. [Migrations](#migrations)

---

## 1. How to read claude:status

```bash
php artisan claude:status
```

### What it prints

The command reads the Redis cache (`claude:healthcheck:anthropic`) and rate limit keys (`claude_rl:*`).

**Block 1 -- Anthropic API Status**

Upstream state:
- `OK` (green) -- Anthropic API responds normally, latency within range
- `DEGRADED` (yellow) -- no fresh ping data, or Anthropic responds with elevated latency
- `DOWN` (red) -- Anthropic does not respond, last ping ended in error

Fields:
- **Latency** -- Anthropic healthcheck response time in milliseconds
- **Error** -- error text (only on DEGRADED/DOWN)
- **Last check** -- timestamp of the last probe

**Block 2 -- Rate Limit Snapshots**

Per-model table with columns:
- **Model** -- model snapshot (for example `claude-sonnet-4-6`)
- **Input Tokens (rem/lim)** -- remaining / limit input tokens
- **Output Tokens (rem/lim)** -- remaining / limit output tokens
- **Requests (rem/lim)** -- remaining / limit request count
- **Recorded At** -- when the snapshot was captured

If no data is present, the output reads `No rate limit data cached.`

**Block 3 -- Global Pause**

If the Redis key `claude:pause:global` is set, a warning is printed:
```text
Global pause is ACTIVE. Run `claude:resume` to clear.
```

### Exit codes

- `0` -- status OK or DEGRADED
- `1` -- status DOWN

For monitoring: use the exit code in cron/healthcheck scripts.

---

## 2. What to do when claude:status = degraded

Step-by-step decision tree.

### Step 1. Check the queues

```bash
php artisan queue:monitor high,default,low,batch
```

If the backlog grows (> 100 jobs in 5 minutes), requests are piling up. Proceed to step 2.

### Step 2. Check failed jobs

```sql
SELECT id, uuid, connection, queue, payload, exception, failed_at
FROM failed_jobs
ORDER BY failed_at DESC
LIMIT 20;
```

If recent failures exist:
- `ProcessAsyncMessage` with timeout -- upstream is slow, proceed to step 4
- `DeliverWebhook` -- the client does not accept the callback, see [section 4](#4-debug-failed-webhook-deliveries)
- Other -- read `exception`, search for the root cause in logs:

```bash
tail -200 storage/logs/llm-*.log | grep -i error
```

### Step 3. Check the streaming pool (listen_queue)

See [section 12](#12-streaming-pool-monitoring). If `listen_queue > 5` is sustained for more than 2 minutes, the streaming pool is overloaded.

### Step 4. Check upstream Anthropic

```bash
curl -s -o /dev/null -w "%{http_code} %{time_total}s" \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  https://api.anthropic.com/v1/models
```

- HTTP 200, time < 2s -- Anthropic is healthy, the issue is on our side
- HTTP 429 -- rate limit, check the budget in `claude:status`
- HTTP 5xx -- problem at Anthropic, proceed to step 6
- Timeout -- network issue or Anthropic outage

Also check: https://status.anthropic.com

### Step 5. Check per-client rate limit budget

```sql
SELECT c.id, c.name, c.rate_limit_rpm, c.monthly_spend_cap_usd, c.current_month_spend_usd
FROM clients c
WHERE c.deleted_at IS NULL
ORDER BY c.current_month_spend_usd DESC
LIMIT 10;
```

If `current_month_spend_usd` is close to `monthly_spend_cap_usd`, the client will soon be rejected by the cap. This is expected behaviour.

If the rate limit data in `claude:status` shows `requests_remaining` close to 0, the Anthropic limit is being exhausted.

### Step 6. Escalate

If the issue is not on our side and Anthropic returns errors, see [section 13](#13-escalation-when-to-file-with-anthropic-support).

If the issue is on our side, escalate to the development team with data from steps 1-5.

---

## 3. How to trigger claude:resume manually

### What it does

Removes the Redis key `claude:pause:global`. After removal, requests to the Anthropic API resume. The next healthcheck ping will run within 1 minute.

### When to use

- After the upstream Anthropic recovers (verified through `claude:status` or a manual curl)
- After a false positive on the circuit breaker
- For a manual release of the global pause

### Syntax

```bash
php artisan claude:resume
```

Output when the pause is active:
```text
Global pause cleared. Requests will resume.
Next healthcheck ping will run within 1 minute.
```

Output when no pause was set:
```text
No pause was active.
Next healthcheck ping will run within 1 minute.
```

### Important

- The command takes no flags or arguments
- The action is immediate and irreversible -- to set a pause again, write the `claude:pause:global` key in Redis manually
- Before clearing the pause, confirm Anthropic is actually working (step 4 of section 2)

---

## 4. Debug failed webhook deliveries

### Where to look

Webhook delivery is recorded in the `async_pending` table.

### Find by request_id

```sql
SELECT
    ap.request_id,
    ap.callback_url,
    ap.status,
    ap.callback_attempts,
    ap.next_attempt_at,
    ap.expires_at,
    ap.created_at,
    ap.updated_at
FROM async_pending ap
WHERE ap.request_id = '<REQUEST_ID>';
```

Statuses:
- `queued` -- awaiting first attempt
- `processing` -- previous attempt failed, awaiting next per backoff
- `delivered` -- delivered successfully
- `exhausted` -- all attempts consumed (default 10)

### Inspect attempt history

`callback_attempts` shows how many attempts were made. `next_attempt_at` is when the next attempt fires.

Backoff: exponential, initial delay 10 seconds, capped at 3600 seconds.
Formula: `min(10 * 2^(attempts-1), 3600)`.

Attempt schedule: 10s, 20s, 40s, 80s, 160s, 320s, 640s, 1280s, 2560s, 3600s.

### Confirm the request finished

```sql
SELECT r.request_id, r.status, r.error_type, r.error_message, r.completed_at
FROM requests r
WHERE r.request_id = '<REQUEST_ID>';
```

If `status = failed_callback_delivery`, all attempts are exhausted. If `status = completed`, the request succeeded but the webhook was not delivered (inspect `async_pending`).

### Error log

```bash
grep '<REQUEST_ID>' storage/logs/llm-*.log
```

On `exhausted` status a log entry is written at `error` level with details:
```text
Webhook delivery exhausted {"request_id": "...", "client_id": ..., "attempts": 10}
```

### Manual retrigger

Reset the status and attempts so the webhook is picked up again:

```sql
UPDATE async_pending
SET status = 'queued',
    callback_attempts = 0,
    next_attempt_at = NOW(),
    updated_at = NOW()
WHERE request_id = '<REQUEST_ID>'
  AND status = 'exhausted';
```

Then dispatch the job manually:

```bash
php artisan tinker --execute="App\Jobs\DeliverWebhook::dispatch('<REQUEST_ID>')->onQueue('default');"
```

### If the client changed callback URL

Check the client's current URLs:

```sql
SELECT * FROM client_callback_urls WHERE client_id = <CLIENT_ID>;
```

If the URL in `async_pending.callback_url` is stale, update it:

```sql
UPDATE async_pending
SET callback_url = '<NEW_URL>',
    status = 'queued',
    callback_attempts = 0,
    next_attempt_at = NOW(),
    updated_at = NOW()
WHERE request_id = '<REQUEST_ID>';
```

Then dispatch the job again (see above).

## Investigate exhausted webhook deliveries

The `async_pending` table moves failed deliveries into `status=exhausted`. Two distinct reasons exist, recorded in the `llm` log channel via the `reason` field:

- `reason=permanent_fail` -- the client endpoint returned a 4xx in the permanent-fail list (`400, 401, 403, 404, 410, 413, 422`, configurable via `config('llm.webhook.permanent_fail_statuses')`). Delivery stops after **one** attempt.
- `reason=transient_fail` -- `default_max_attempts` (10) transient failures consumed: 5xx, 408, 425, 429, network or `RequestException`.

Filter recent exhaustions by reason:

    grep '"Webhook delivery exhausted"' storage/logs/llm-$(date +%Y-%m-%d).log \
      | jq 'select(.context.reason == "permanent_fail")'

Count per reason over the last hour (the `llm` channel writes daily JSON-line files; there is no log table):

    grep '"Webhook delivery exhausted"' storage/logs/llm-$(date +%Y-%m-%d).log \
      | jq -r --arg since "$(date -u -d '1 hour ago' +%Y-%m-%dT%H:%M:%S)" \
          'select(.datetime >= $since) | .context.reason' \
      | sort | uniq -c

For `permanent_fail`: contact the client -- their endpoint is broken or the auth header is stale.
For `transient_fail`: check client endpoint availability and whether responses exceed `WEBHOOK_CONNECT_TIMEOUT_SECONDS` (default 5s) or `webhook.request_timeout_seconds` (30s).

---

## 5. Debug stuck batch

### Symptoms

- Batch stays in `in_progress` for more than 24 hours
- `claude:poll-batches` does not transition it to a terminal status
- Client reports a stuck batch

### Diagnostics

```sql
SELECT
    b.id,
    b.batch_id,
    b.anthropic_batch_id,
    b.client_id,
    b.status,
    b.request_count,
    b.succeeded_count,
    b.errored_count,
    b.cancelled_count,
    b.expired_count,
    b.poll_attempts,
    b.submitted_at,
    b.completed_at,
    b.created_at,
    b.updated_at
FROM batches b
WHERE b.status = 'in_progress'
  AND b.submitted_at < NOW() - INTERVAL 24 HOUR
ORDER BY b.submitted_at ASC;
```

### Check status directly with Anthropic

```bash
curl -s \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  "https://api.anthropic.com/v1/messages/batches/<ANTHROPIC_BATCH_ID>" | jq .
```

Possible `processing_status` values:
- `in_progress` -- Anthropic is still processing
- `ended` -- finished, but we missed it (polling bug or cooldown)
- 404 -- batch not found on Anthropic side

### Inspect batch items

```sql
SELECT
    bi.status,
    COUNT(*) as cnt
FROM batch_items bi
WHERE bi.batch_id = <BATCH_ID>
GROUP BY bi.status;
```

### Anthropic returns `ended` while we still see `in_progress`

Check whether a polling cooldown is set:

```bash
php artisan tinker --execute="echo \Illuminate\Support\Facades\Cache::get('claude:batch-poll-cooldown') ? 'cooldown active' : 'no cooldown';"
```

Force a poll:

```bash
php artisan claude:poll-batches
```

### Force close (last resort)

If the batch is stuck on the Anthropic side and has not moved for over 48 hours:

```sql
UPDATE batches
SET status = 'failed',
    completed_at = NOW(),
    updated_at = NOW()
WHERE batch_id = '<BATCH_ID>'
  AND status = 'in_progress';
```

Notify the client that the batch was closed forcibly. Items still in `pending` must be resubmitted.

---

## 6. How to update model aliases

Details: `documentation/internal_logic.md`, section 20.

### Checklist

1. Update `config/llm.php` -- section `claude.model_aliases` and, if needed, `claude.model_capabilities`
2. Run sync:
   ```bash
   php artisan claude:sync-capabilities
   ```
   The command queries live capabilities from Anthropic and prints the drift (config mismatches).
3. Run tests:
   ```bash
   php artisan test --testsuite=Unit
   php artisan test --testsuite=Feature
   ```
4. Deploy via the standard procedure
5. Monitor for 24 hours: `claude:status`, error logs, rate limit budget

### Important

- The alias (for example `claude-sonnet`) stays stable; only the snapshot changes (for example `claude-sonnet-4-6` -> `claude-sonnet-4-7`)
- Clients use the alias, not the snapshot -- the update is transparent for them
- After the snapshot update the rate limit budget is counted against the new model

---

## 7. How to update pricing

Details: `documentation/internal_logic.md`, section 19.

### Checklist

1. Update `config/llm.php` -- section `claude.pricing`
2. Inspect current prices:
   ```bash
   php artisan claude:price-check
   ```
   Prints the table: alias, snapshot, input/output/cache/batch prices per 1M tokens, server tools pricing.
3. Run tests:
   ```bash
   php artisan test --testsuite=Unit
   ```
4. Notify clients (template below)
5. Deploy
6. Monitor spend after the update:
   ```sql
   SELECT c.name, c.current_month_spend_usd, c.monthly_spend_cap_usd
   FROM clients c
   WHERE c.deleted_at IS NULL
   ORDER BY c.current_month_spend_usd DESC;
   ```

### Client notification template

```text
Subject: LLM Gateway pricing update -- effective [DATE]

Dear team,

We are updating the pricing for LLM Gateway usage,
effective [DATE].

Affected models: [MODEL LIST, e.g.: claude-sonnet, claude-opus]

Previous pricing (per 1M tokens):
- Input: $[OLD_PRICE]
- Output: $[OLD_PRICE]

New pricing (per 1M tokens):
- Input: $[NEW_PRICE]
- Output: $[NEW_PRICE]

Full pricing table available via API: GET /internal/stats

If you have budget caps configured (monthly_spend_cap_usd),
we recommend reviewing them in light of the new rates.

Questions: [CONTACT]
```

---

## 8. How to update beta headers

Details: `documentation/internal_logic.md`, section 18.

### Checklist

1. Update `config/llm.php` -- section `claude.beta_headers`
   Current headers:
   - `files_api` -- `files-api-2025-04-14`
   - `compaction` -- `compact-2026-01-12`
   - `context_management` -- `context-management-2025-06-27`
   - `output_300k` -- `output-300k-2026-03-24`
   - `mcp_client` -- `mcp-client-2025-11-20`
   - `fast_mode` -- `fast-mode-2026-02-01`
   - `computer_use` -- `computer-use-2025-01-24`
   - `skills` -- `skills-2025-10-02`

2. Run tests:
   ```bash
   php artisan test --testsuite=Unit
   ```

3. Deploy

4. Verify requests pass through:
   ```bash
   php artisan claude:status
   ```
   If status is OK, headers are correct. If DEGRADED/DOWN with `invalid_request_error`, the header is invalid -- roll back.

### Important

- Anthropic periodically promotes beta features to GA and removes the header. A stale header triggers an error.
- When dropping a header from config, confirm the feature is available without it (consult the Anthropic changelog).
- Header order does not matter; they are concatenated with commas.

---

## 9. How to clean up orphaned files

### Automatic cleanup

```bash
php artisan claude:cleanup-files
```

The command runs two passes:
1. **Hard-delete pass** -- removes rows from the `files` table where `is_deleted = 1` and more than `hard_delete_grace_days` (default 14 days) have passed since soft delete
2. **Unused alert pass** -- detects files unused for more than `unused_alert_days` (default 90 days) and logs a warning

### Manual SQL cleanup

Find orphaned files (soft-deleted, older than the grace period):

```sql
SELECT f.id, f.file_id, f.anthropic_file_id, f.filename, f.size_bytes, f.deleted_at
FROM files f
WHERE f.deleted_at IS NOT NULL
  AND f.deleted_at < NOW() - INTERVAL 14 DAY
ORDER BY f.size_bytes DESC;
```

Delete a specific file:

```sql
DELETE FROM files WHERE file_id = '<FILE_ID>';
```

### Recompute storage quota

After bulk cleanup, check the total file size per client:

```sql
SELECT
    f.client_id,
    c.name,
    COUNT(*) as file_count,
    SUM(f.size_bytes) as total_bytes,
    ROUND(SUM(f.size_bytes) / 1048576, 2) as total_mb
FROM files f
JOIN clients c ON f.client_id = c.id
WHERE f.deleted_at IS NULL
GROUP BY f.client_id, c.name
ORDER BY total_bytes DESC;
```

---

## 10. How to extract client data by request_id

### Core request information

```sql
SELECT
    r.request_id,
    r.client_id,
    c.name AS client_name,
    r.endpoint,
    r.mode,
    r.model_alias,
    r.model_snapshot,
    r.anthropic_request_id,
    r.status,
    r.http_status,
    r.error_type,
    r.error_message,
    r.service_tier_used,
    r.created_at,
    r.started_at,
    r.completed_at
FROM requests r
JOIN clients c ON r.client_id = c.id
WHERE r.request_id = '<REQUEST_ID>';
```

### Source payload and response

```sql
SELECT
    rr.request_id,
    rr.request_payload,
    rr.response_payload,
    rr.retention_until
FROM request_raw rr
WHERE rr.request_id = '<REQUEST_ID>';
```

Retention: `request_raw` is kept for `raw_log_retention_days` days (default 14). After that period the data is purged by `requests:cleanup`.

### Cost breakdown

```sql
SELECT
    ru.request_id,
    ru.input_tokens,
    ru.output_tokens,
    ru.cache_creation_5m_tokens,
    ru.cache_creation_1h_tokens,
    ru.cache_read_tokens,
    ru.thinking_tokens,
    ru.server_tool_web_search_count,
    ru.server_tool_code_exec_count,
    ru.cost_usd,
    ru.cost_breakdown
FROM request_usage ru
WHERE ru.request_id = '<REQUEST_ID>';
```

`cost_breakdown` is a JSON document with per-component detail: input, output, cache_write_5m, cache_write_1h, cache_read, server tools.

### Webhook delivery log

```sql
SELECT
    ap.request_id,
    ap.callback_url,
    ap.status,
    ap.callback_attempts,
    ap.next_attempt_at,
    ap.expires_at
FROM async_pending ap
WHERE ap.request_id = '<REQUEST_ID>';
```

### PII

The `request_raw` table stores full request and response payloads, including user content. When sharing data with third parties or honouring a client deletion request:

- Confirm the request is authorised
- Do not copy `request_payload`/`response_payload` into unsecured channels
- To delete:
  ```sql
  DELETE FROM request_raw WHERE request_id = '<REQUEST_ID>';
  ```

## Adjust client rate limit

1. Find the client:

   ```sql
   SELECT id, name, rate_limit_rpm FROM clients WHERE name = '<client-name>';
   ```

2. Update the limit:

   ```sql
   UPDATE clients SET rate_limit_rpm = <N> WHERE id = <id>;
   ```

3. No cache to invalidate -- the rate limiter reads `rate_limit_rpm` on every request through `App\Providers\AppServiceProvider::boot()` -> `RateLimiter::for('api-client', ...)`.
4. Verify by sending a burst of test requests with the client's API key. Expect HTTP 429 after `N` requests inside one minute, with `Retry-After` and `X-RateLimit-*` headers in the response.

Setting `rate_limit_rpm = NULL` falls back to `config('llm.rate_limit.default_per_minute')` (default 600/min, env `GATEWAY_DEFAULT_RATE_LIMIT_PER_MINUTE`).

---

## 11. Drop legacy tables safeguard

**CRITICAL.** One-time operation that drops obsolete tables from the previous schema version.

### Tables removed

The migration `2026_05_01_000001_drop_legacy_tables` drops:
- `session_history`
- `pending_responses`
- `pending_prompts`
- `raw_responses`
- `response_log`
- `request_log`
- `callback_urls`
- `api_clients`
- `jobs`

### Safeguard mechanism

The migration is gated by an environment variable. Without it, `php artisan migrate` throws `RuntimeException` and stops.

### Step-by-step procedure

**Step 1.** Set the environment variable:

```bash
export CLAUDE_ALLOW_LEGACY_DROP=yes-i-confirm-data-loss-2026-05
```

Or add it to `.env`:
```text
CLAUDE_ALLOW_LEGACY_DROP=yes-i-confirm-data-loss-2026-05
```

The value must be **exactly** `yes-i-confirm-data-loss-2026-05`. Any other value fails the check.

**Step 2.** Run the migration:

```bash
php artisan migrate --force
```

`--force` is required in production.

**Step 3.** Remove the environment variable:

```bash
unset CLAUDE_ALLOW_LEGACY_DROP
```

Or delete the line from `.env`. The variable must not remain in the environment after the migration.

**Step 4.** Verify:

```sql
SHOW TABLES;
```

Confirm that tables `api_clients`, `callback_urls`, `request_log`, `response_log`, `raw_responses`, `pending_prompts`, `pending_responses`, `session_history`, `jobs` are absent.

### Important

- This is a one-time operation. Once executed, the migration is marked as run in `migrations` and will not fire again.
- Rollback (`migrate:rollback`) is not possible -- the migration's `down()` throws RuntimeException. Recovery is only from backup.
- The safeguard is implemented via env variable on purpose: it is the only way to guarantee the operator deliberately confirmed data loss.
- Confirm the database backup exists before running it.

---

## 12. Streaming pool monitoring

### How streaming works

Streaming requests are served by php-fpm processes directly (not via queues). Each streaming request occupies one php-fpm worker for the entire duration of the stream (timeout up to 1800 seconds).

### How to check listen_queue

```bash
docker exec llm-gateway-php-fpm bash -c "SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect /var/run/php-fpm.sock"
```

Or via nginx (if the endpoint is exposed):
```bash
curl -s http://localhost:8080/fpm-status
```

Key metrics:
- **listen queue** -- number of requests waiting for a free worker
- **active processes** -- number of busy workers
- **idle processes** -- number of free workers
- **max active processes** -- peak value since startup

### Alert triggers

| Condition | Level | Action |
|-----------|-------|--------|
| `listen_queue > 5` sustained for 2 minutes | WARNING | Check the streaming request volume |
| `listen_queue > 10` sustained for 1 minute | CRITICAL | Scale the pool |
| `idle processes = 0` while `listen_queue > 0` | CRITICAL | All workers busy, new requests blocked |

### How to scale

Increase `pm.max_children` in the php-fpm configuration:

1. Edit `docker/php-fpm/www.conf` (or the equivalent config file):
   ```ini
   pm.max_children = <NEW_VALUE>
   ```

2. Restart the php-fpm container:
   ```bash
   docker compose restart php-fpm
   ```

### Risk factors

- Each streaming worker holds a connection for up to 1800 seconds (30 minutes) -- `config/llm.php` -> `claude.timeouts.streaming`
- With 20 workers and a 60-second average stream the throughput is ~20 rps streaming
- Spikes of long streams (thinking with extended output) drain workers faster
- Raising `pm.max_children` requires proportional RAM growth (each worker ~50-100MB)
- Watch `connection_aborted` -- if the client disconnects, the worker still drains the response from Anthropic for accurate billing

### Webhook exhaustion metrics

Track permanent-fail vs transient-fail rates separately -- they reflect different failure modes:

- A spike in `reason=permanent_fail` indicates a broken client endpoint (auth rotation, deployment that changed the URL, payload validation rejecting the envelope). It is not the gateway's problem to retry.
- A spike in `reason=transient_fail` indicates either client-endpoint instability or upstream network issues. Both surface as exhausted rows after 10 attempts (~90 minutes total backoff).

---

## 13. Escalation: when to file with Anthropic support

### Symptoms that warrant escalation

| Symptom | Threshold | Source |
|---------|-----------|--------|
| HTTP 5xx from Anthropic | > 1% of all requests sustained > 5 minutes | `requests.http_status`, logs |
| HTTP 429 on every request | All models, all clients simultaneously | `claude:status` rate limit snapshot |
| Latency spike | > 3x baseline (baseline ~2-5s for sonnet) | `claude:status` latency, `requests.started_at`/`completed_at` |
| Batch never completes | > 48 hours in `in_progress` | `batches.submitted_at` |
| Files API errors | Steady 4xx/5xx on file uploads | Logs |

### What to include in the ticket

1. **Request IDs** -- our `request_id` and `anthropic_request_id` from the `requests` table:
   ```sql
   SELECT request_id, anthropic_request_id, http_status, error_type, error_message, created_at
   FROM requests
   WHERE http_status >= 500
     AND created_at > NOW() - INTERVAL 1 HOUR
   ORDER BY created_at DESC
   LIMIT 20;
   ```

2. **Timestamps** -- exact UTC interval of the issue

3. **Repro payload** -- minimal request that reproduces the issue (from `request_raw.request_payload`):
   ```sql
   SELECT rr.request_payload
   FROM request_raw rr
   JOIN requests r ON rr.request_id = r.request_id
   WHERE r.http_status >= 500
     AND r.created_at > NOW() - INTERVAL 1 HOUR
   LIMIT 1;
   ```
   Strip sensitive client data from the payload before sending.

4. **Error response** -- response body from Anthropic (from `request_raw.response_payload`)

5. **Frequency** -- error percentage and number of affected requests

6. **Organization ID** -- from `requests.anthropic_organization_id`

### Anthropic contacts

- Support portal: https://support.anthropic.com
- API status page: https://status.anthropic.com
- For critical incidents (full outage): set `Severity: Critical` on the ticket

---

## Migrations

### `2026_04_25_091207_reorder_async_pending_indexes`

Drops the previous composite index `[status, next_attempt_at]` and creates `async_pending_next_attempt_status_idx (next_attempt_at, status)`. The leading column matters: the scheduler `RetryFailedWebhooks` filters by `next_attempt_at <= NOW()` first, then by `status`.

**Production rollout (MySQL 8.4, InnoDB):**

    ALTER TABLE async_pending
      DROP INDEX async_pending_status_next_attempt_at_index,
      ADD INDEX async_pending_next_attempt_status_idx (next_attempt_at, status),
      ALGORITHM=INPLACE, LOCK=NONE;

`ALGORITHM=INPLACE, LOCK=NONE` avoids downtime on large tables. Laravel's migration wrapper does not emit these clauses -- for a production DB with >10M rows, run the raw SQL manually before invoking `php artisan migrate` (then mark the migration as run via `INSERT INTO migrations`).

For dev, test or small prod (<1M rows), `php artisan migrate` is sufficient.

## Rotating APP_KEY

`APP_KEY` is the Laravel encryption key that protects every `*_encrypted` column in the database:

- `claude_workspaces.api_key_encrypted` — Anthropic API keys
- `clients.signing_secret_current_encrypted` and `clients.signing_secret_previous_encrypted` — webhook HMAC secrets
- `sessions.mcp_servers[*].authorization_token` — per-session MCP server tokens

Rotating `APP_KEY` without re-encrypting these columns leaves the gateway unable to decrypt them. Symptom: every request that needs an upstream call returns 500 with `DecryptException: The MAC is invalid.` in `storage/logs/laravel.log`. The healthcheck reports the Anthropic component as `down` with the error `default workspace api_key cannot be decrypted (likely APP_KEY rotated without re-encryption)`.

### Procedure

1. **Generate the new key** *without* writing it to `.env` yet:

        php artisan key:generate --show

2. **Save the previous key** to `APP_OLD_KEY` and the new one to `APP_KEY` in your `.env` (or secret manager). Both keys must coexist for the duration of the migration.

3. **Recreate the application containers** so they pick up the new env. Long-running workers (`llm_queue_worker`, `llm_scheduler`) cache `APP_KEY` from `docker-compose env_file` at container creation; a hot `.env` edit is *not* enough:

        docker compose up -d --force-recreate llm_gateway llm_queue_worker llm_scheduler

4. **Dry-run the re-encryption** to see how many rows will be touched:

        APP_OLD_KEY="$(grep ^APP_OLD_KEY .env | cut -d= -f2-)" \
          docker compose exec -T -e APP_OLD_KEY llm_gateway \
          php artisan keys:reencrypt --dry-run

5. **Apply the re-encryption.** The command is idempotent: rows already encrypted with the new key are skipped.

        APP_OLD_KEY="$(grep ^APP_OLD_KEY .env | cut -d= -f2-)" \
          docker compose exec -T -e APP_OLD_KEY llm_gateway \
          php artisan keys:reencrypt

   Output reports per-row outcomes (`ok` / `reencrypted` / `failed`) and a final summary. Exit code is non-zero only if at least one row failed.

6. **Verify** that healthcheck recovers within ~60 s (the scheduler ping caches its result for 90 s):

        curl -sf http://localhost:8080/internal/health | jq '.components.anthropic.status'

   Expected: `"ok"`.

7. **Remove `APP_OLD_KEY` from `.env`** and recreate the gateway so the previous key is no longer reachable.

### Notes

- `keys:reencrypt` reads `APP_OLD_KEY` from the process environment only — it never writes the key to logs, never accepts it as a CLI argument, and never echoes it. Pass it via env-only invocation as shown above.
- Failed rows (e.g. data encrypted with a key that is neither `APP_KEY` nor `APP_OLD_KEY`) are reported per row but do not abort the run; review the failures and re-run with the appropriate `APP_OLD_KEY` if needed.
- Soft-deleted clients are included so their secrets remain decryptable for audit.
