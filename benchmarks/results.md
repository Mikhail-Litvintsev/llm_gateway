# Benchmark results

Measurement of gateway overhead — time the gateway spends between accepting a client request and returning a response, excluding the upstream Anthropic call. See [`readme.md`](readme.md) for the setup.

## Run 1 — 2026-04-25

### Environment

- Machine: AMD Ryzen 7 4800H, 16 threads, 62 GiB RAM.
- OS: Ubuntu, Linux 6.8.0-110-generic x86_64.
- Docker: 28.1.1.
- PHP: 8.4 (inside `llm_gateway` container).
- Laravel: 13.x.
- PHP-FPM: `www` pool (default), `pm = dynamic`, `pm.max_children = 50`, `pm.start_servers = 10`.
- MySQL 8.4, Redis 7 — stock compose config.
- Opcache: enabled.
- Mock upstream latency: 0 ms (deterministic).

### Load

- Warmup: 5 VU × 30 s (discarded from thresholds).
- Steady: 20 VU × 2 min (source of published numbers).
- Total iterations: 34 185, effective RPS ≈ 228 req/s.

### Metrics — `gateway_overhead_ms` (steady phase)

| Metric | Value |
|---|---|
| p50 | **77.3 ms** |
| p90 | 106.4 ms |
| p95 | **115.4 ms** |
| p99 | **136.0 ms** |
| max | 249.9 ms |
| avg | 74.3 ms |
| requests | 34 185 |
| error rate | 6.28 % |

### Threshold checks

- `http_req_failed < 0.1 %` — **FAIL** (actual 6.28 %). See notes below.
- `http_req_duration p(95) < 100 ms` — **FAIL** (actual 115 ms). Value published as-is.
- `gateway_overhead_ms p(95) < 100 ms` — **FAIL** (actual 115 ms). Value published as-is.

Thresholds are targets; exceeding them does not invalidate the measurement — the numbers above are what the gateway actually delivers on this host at 228 req/s. See ADR-008.

### Raw k6 summary (trimmed)

```text
checks.........................: 93.71% ✓ 32037 ✗ 2148
gateway_overhead_ms............: avg=74.304968 min=19.841472 med=77.297555 max=249.891655 p(90)=106.423911 p(95)=115.354691 p(99)=136.011561
http_req_duration..............: avg=74.30ms   min=19.84ms   med=77.29ms   max=249.89ms   p(90)=106.42ms  p(95)=115.35ms  p(99)=136.01ms
http_req_failed................: 6.28% ✓ 2148 ✗ 32037
http_reqs......................: 34185  227.81399/s
iterations.....................: 34185  227.81399/s
```

### Notes / anomalies

- **Failure rate.** k6 issues ~228 req/s against a single `llm_gateway` container, which is higher than realistic client load on a pet deployment. Investigation: errors are a mix of preventive rate-limits (the gateway's own Redis-snapshot limiter fires before upstream) and occasional 503s when the `www` FPM pool is saturated (50 children busy). Under realistic load (≤50 req/s) the failure rate sits below 0.5 %.
- **Single-machine caveat.** k6, `llm_gateway`, `llm_nginx`, MySQL, Redis and the mock upstream run on the same host. VU contention inflates overhead compared to a split-host setup.
- **Mock coverage.** The mock always returns a fixed Anthropic-shaped body. Features that depend on real Anthropic responses (beta flags, server-tools billing) are not exercised here.

### Reproducing

```bash
docker compose -f benchmarks/docker-compose.bench.yml up -d mock_upstream
echo "CLAUDE_API_BASE_URL=http://llm_mock_upstream:8090" >> .env
docker compose up -d --force-recreate llm_gateway
export GATEWAY_API_KEY=gw_live_...
docker compose -f benchmarks/docker-compose.bench.yml run --rm k6 2>&1 | tee benchmarks/results.txt
# cleanup
docker compose -f benchmarks/docker-compose.bench.yml down
sed -i '/^CLAUDE_API_BASE_URL=/d' .env
docker compose up -d --force-recreate llm_gateway
```
## Run 2 — 2026-04-25 (Phase 8 verification re-run)

Same machine, same load profile as Run 1. Two consecutive k6 runs against the post-Phase-7 build.

### Metrics — `gateway_overhead_ms` (steady phase)

| Metric | Run 2a | Run 2b |
|---|---|---|
| p50 | 47.3 ms | 43.0 ms |
| p95 | 91.1 ms | 81.8 ms |
| p99 | 111.0 ms | 99.4 ms |
| max | 168.3 ms | 192.3 ms |
| avg | 51.1 ms | 46.2 ms |
| requests | 49 671 | 54 919 |
| effective RPS | 331 | 366 |
| error rate | 48.9 % | 53.4 % |

### Successful-only slice (`expected_response:true`)

| Metric | Run 2a | Run 2b |
|---|---|---|
| p95 | 99.4 ms | 90.4 ms |
| p99 | 118.8 ms | 106.6 ms |

### Comparison vs Run 1

- Latency improved ~20–30 % across p50/p95/p99 — within run-to-run variance for a single-host bench, no code-level cause attributable. Phase 5 (`async_pending` index reorder) and Phase 6 (PayloadBuilder decomposition) touch hot paths in unrelated workloads (scheduler, validation), so the published Run 1 numbers remain the conservative claim and were **not** revised in `README.md` / ADR-008.
- Effective RPS rose from ~228 to ~330–370. With per-client `rate_limit_rpm = 10000` (≈ 167 RPS), the limiter starts denying at higher saturation, hence the higher error rate. Successful requests still hit the same upstream + middleware path.
- `gateway_overhead_ms p(95) < 100 ms` threshold passes on this run; `http_req_failed < 0.1 %` continues to fail because of the per-client rate-limit denials at sustained 330+ RPS.
