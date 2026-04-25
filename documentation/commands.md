# CLI Commands

## Client management

### `client:create`

Create a new API client with key and signing secret.

```bash
php artisan client:create {name}
    [--model-alias=]
    [--rate-limit=]
    [--monthly-cap=]
    [--features=*]
```

| Parameter | Description |
|-----------|-------------|
| `name` | Client name (required) |
| `--model-alias` | Default model alias |
| `--rate-limit` | RPM (requests per minute) |
| `--monthly-cap` | Monthly cap in USD |
| `--features` | Allowed features (comma-separated) |

Prints the API key (`gw_live_...`) and signing secret. Both values are shown only once.

File: `app/Console/Commands/ClientCreate.php`

---

### `client:show`

Show client details and usage stats (no secrets).

```bash
php artisan client:show {client_id}
```

File: `app/Console/Commands/ClientShow.php`

---

### `client:rotate-key`

Rotate API key for a client. The operation is atomic and offers no grace period.

```bash
php artisan client:rotate-key {client_id}
```

File: `app/Console/Commands/ClientRotateKey.php`

---

### `client:rotate-secret`

Rotate the webhook signing secret. A 24-hour grace period applies — both secrets are accepted during that window.

```bash
php artisan client:rotate-secret {client_id}
```

File: `app/Console/Commands/ClientRotateSecret.php`

---

### `client:enable-feature`

Enable a feature for a client.

```bash
php artisan client:enable-feature {client_id} {feature}
```

File: `app/Console/Commands/ClientEnableFeature.php`

---

### `client:disable-feature`

Disable a feature for a client.

```bash
php artisan client:disable-feature {client_id} {feature}
```

File: `app/Console/Commands/ClientDisableFeature.php`

---

## Claude API

### `claude:status`

Show Claude API health status and rate limit snapshot: queues, rate limit budget, failed jobs, streaming pool.

```bash
php artisan claude:status
```

File: `app/Console/Commands/ClaudeStatus.php`

---

### `claude:resume`

Clear the global pause flag for Claude API requests (deletes the Redis key `claude:pause:global`). The next healthcheck ping fires within a minute.

```bash
php artisan claude:resume
```

File: `app/Console/Commands/ClaudeResume.php`

---

### `claude:price-check`

Display the Claude pricing table with model aliases.

```bash
php artisan claude:price-check
```

File: `app/Console/Commands/ClaudePriceCheck.php`

---

### `claude:sync-capabilities`

Fetch live model capabilities from Anthropic and detect drift from config (context window, max output, supported features).

```bash
php artisan claude:sync-capabilities
```

File: `app/Console/Commands/SyncClaudeCapabilities.php`

---

### `claude:cleanup-files`

Hard-delete orphaned file records and alert on unused files (records without an owner or past their TTL).

```bash
php artisan claude:cleanup-files
```

File: `app/Console/Commands/Claude/CleanupOrphanedFilesScheduled.php`

---

### `claude:flush-accumulator`

Flush batch accumulator buckets that have reached their trigger thresholds (manual flush of accumulated items).

```bash
php artisan claude:flush-accumulator
```

File: `app/Console/Commands/Claude/FlushBatchAccumulatorScheduled.php`

---

### `claude:poll-batches`

Poll Anthropic for in-progress batch statuses (manual polling of active batches).

```bash
php artisan claude:poll-batches
```

File: `app/Console/Commands/Claude/PollBatchesScheduled.php`

---

## Maintenance

### `requests:cleanup`

TTL-based cleanup of expired requests, raw data, and async pending records, keyed off `created_at` in the `requests` table:

- `async_pending` — rows with expired `expires_at` (older than 1 day).
- `request_raw` — older than `llm.raw_log_retention_days` (default 14).
- `request_usage` and `requests` — older than `llm.session_default_ttl_days` (default 30).

```bash
php artisan requests:cleanup
```

File: `app/Console/Commands/RequestsCleanup.php`

---

### `webhook:cleanup-expired-secrets`

Nullify previous signing secrets that have exceeded the grace period.

```bash
php artisan webhook:cleanup-expired-secrets
```

File: `app/Console/Commands/WebhookCleanupExpiredSecrets.php`

---

### `queue:monitor`

Display queue depths, `failed_jobs` count, and stuck async requests. Use as a CLI replacement for Horizon (see [ADR-002](decisions.md#adr-002-no-horizon)).

```bash
php artisan queue:monitor
```

File: `app/Console/Commands/MonitorQueue.php`

---

## Testing

### `llm:create-test-db`

Create the test database `llm_gateway_test` if it does not exist.

```bash
php artisan llm:create-test-db
    [--host=127.0.0.1]
    [--port=3307]
    [--root-password=root_secret]
```

File: `app/Console/Commands/CreateTestDatabase.php`
