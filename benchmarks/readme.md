# Gateway overhead benchmark

## What it measures

The time LLM Gateway spends between receiving a client request and returning the response, excluding the upstream Anthropic call. Upstream is replaced by a deterministic mock that returns a valid Anthropic-shaped body.

## Not measured

End-to-end LLM response latency. That is dominated by Anthropic (typically 1–5+ seconds) and is outside the gateway's control. See [ADR-008](../documentation/decisions.md#adr-008-p95-overhead-measurement-not-full-response-latency) for the rationale.

## Prerequisites

- Docker Compose stack running (`./start.sh`).
- A test client with a known `gw_live_*` key. Create it via:

  ```
  docker compose exec -T llm_gateway php artisan client:create bench \
    --model-alias=claude-sonnet --rate-limit=1000 --monthly-cap=1000
  ```

  The command prints `gw_live_*` once — copy the value and export it:

  ```
  export GATEWAY_API_KEY=gw_live_...
  ```

## Running

1. Start the main stack (`./start.sh`) — it creates the `llm_internal` network.
2. Point the gateway at the mock upstream by adding one line to `.env` (temporary!):

   ```
   echo "CLAUDE_API_BASE_URL=http://llm_mock_upstream:8090" >> .env
   ```

   Inline `CLAUDE_API_BASE_URL=... docker compose up ...` does **not** work — the variable is exported for the compose CLI, not placed inside the container. The container reads from `.env`.

3. Recreate `llm_gateway` so it picks up the new env:

   ```
   docker compose up -d --force-recreate llm_gateway
   ```

4. Start mock-upstream and run k6. Use the standalone bench compose file — never combine with the main compose (`docker-compose.yml` defines `llm_internal` with `driver: bridge + ipam`, bench references it as `external: true`; merging the two fails with a network-conflict error):

   ```
   docker compose -f benchmarks/docker-compose.bench.yml up -d mock_upstream
   docker compose -f benchmarks/docker-compose.bench.yml run --rm k6 2>&1 | tee benchmarks/results.txt
   ```

5. Cleanup — the part that's easy to forget:

   ```
   docker compose -f benchmarks/docker-compose.bench.yml down
   sed -i '/^CLAUDE_API_BASE_URL=/d' .env
   docker compose up -d --force-recreate llm_gateway
   curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8080/internal/health
   ```

   Expected last output: `200`.

## Scenarios

- `warmup` — 5 VU × 30s, not counted in thresholds.
- `steady` — 20 VU × 2m, source of the published numbers.

## Interpretation

- `gateway_overhead_ms.p(95) < 100` — acceptable for pet-scope.
- `> 500` ms — investigate PHP-FPM pool sizing, hot-path DB writes, or opcache misconfig.
- `http_req_failed > 0.1%` — mock is unhealthy, or preventive rate-limit kicked in.

## Known limitations

- Mock-upstream returns a fixed body; gateway features that depend on real Anthropic responses (beta behavior, server tools accounting) are not exercised. The benchmark covers the generic POST `/v1/messages` hot path only.
- 20 VU is modest; at larger concurrency the numbers will shift. ADR-008 restates this caveat.
- First-run values include PHP-FPM warmup; that's why the `warmup` phase is discarded from thresholds.
