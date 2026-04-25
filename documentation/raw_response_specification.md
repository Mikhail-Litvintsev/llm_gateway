# Raw request and response storage

## Purpose

The gateway stores raw request and response bodies for audit, debug, replay and compliance. The data is split across three tables: `requests` (structured metadata), `request_usage` (per-request token and cost breakdown), `request_raw` (full request and response bodies as JSON).

---

## `requests` schema

Structured log of every request.

| Column | Type | Description |
|---|---|---|
| `request_id` | `char(28)` PK | Unique request id (`req_...`) |
| `client_id` | `bigint unsigned` FK → `clients.id` | Owning client |
| `endpoint` | `enum` | Type: `messages`, `batch_item`, `count_tokens`, `session_message` |
| `mode` | `enum` | Mode: `sync`, `sync_stream`, `async_callback`, `batch` |
| `model_alias` | `varchar` | Requested alias (`claude-sonnet`) |
| `model_snapshot` | `varchar` | Resolved snapshot (`claude-sonnet-4-6`) |
| `anthropic_request_id` | `varchar` nullable | Upstream request id from Anthropic |
| `anthropic_organization_id` | `varchar` nullable | Organization id returned by Anthropic |
| `status` | `varchar(32)` | One of `App\Components\Logging\Enums\RequestStatus`: `accepted`, `in_progress`, `completed`, `completed_disconnected`, `failed_client_error`, `failed_server_error`, `failed_callback_delivery`, `failed_validation`, `failed_auth` |
| `http_status` | `smallint unsigned` nullable | HTTP status from Anthropic (200, 400, 429, …) |
| `error_type` | `varchar` nullable | Error type (`overloaded_error`, `rate_limit_error`, …) |
| `error_message` | `text` nullable | Error message |
| `service_tier_used` | `varchar` nullable | Service tier returned by Anthropic (`standard`, `auto`) |
| `created_at` | `datetime` | Time the request was accepted |
| `started_at` | `datetime` nullable | Time Anthropic processing started |
| `completed_at` | `datetime` nullable | Time the response was received |

Indexes: `(client_id, created_at)`, `(status)`.

---

## `request_usage` schema

Per-request token and cost breakdown. 1:1 with `requests`.

| Column | Type | Description |
|---|---|---|
| `request_id` | `char(28)` PK, FK → `requests.request_id` | |
| `input_tokens` | `bigint unsigned` | Input tokens |
| `output_tokens` | `bigint unsigned` | Output tokens |
| `cache_creation_5m_tokens` | `bigint unsigned` | Tokens written to cache (5m TTL) |
| `cache_creation_1h_tokens` | `bigint unsigned` | Tokens written to cache (1h TTL) |
| `cache_read_tokens` | `bigint unsigned` | Tokens read from cache |
| `thinking_tokens` | `bigint unsigned` | Thinking tokens |
| `server_tool_web_search_count` | `int unsigned` | `web_search` invocations |
| `server_tool_web_fetch_count` | `int unsigned` | `web_fetch` invocations |
| `server_tool_code_exec_count` | `int unsigned` | `code_execution` invocations |
| `server_tool_tool_search_count` | `int unsigned` | `tool_search` invocations |
| `cost_usd` | `decimal(12,8)` | Total cost in USD |
| `cost_breakdown` | `json` | Cost broken down by category |
| `iterations_json` | `json` nullable | Per-iteration data (agentic loops) |
| `rate_limit_headers` | `json` nullable | Rate-limit headers returned by Anthropic |

---

## `request_raw` schema

Full request and response bodies. 1:1 with `requests`.

| Column | Type | Description |
|---|---|---|
| `request_id` | `char(28)` PK, FK → `requests.request_id` | |
| `request_payload` | `longtext` | Full request body (JSON) |
| `response_payload` | `longtext` nullable | Full response body (JSON). `null` while the request is still in flight |
| `retention_until` | `datetime` | Calculated TTL marker, set as `created_at + raw_log_retention_days` (default 14 from `config/llm.php` → `raw_log_retention_days`). Informational only — `requests:cleanup` deletes by `requests.created_at`, not by this column. |

Both `request_payload` and `response_payload` are persisted through `App\Components\Logging\PayloadMasker::mask()`, which redacts `authorization`/`x-api-key` headers and any Anthropic key-shaped strings inside the body before the row is written. Downstream consumers see only the masked form.

---

## `response_payload` shape

When `response_payload` is non-null, it contains the byte-for-byte Anthropic Messages API response body that the gateway returned to the client (or accumulated from the SSE stream for sync-stream and async modes). The canonical shape:

```json
{
  "id": "msg_01XYZ...",
  "type": "message",
  "role": "assistant",
  "model": "claude-sonnet-4-6",
  "content": [
    {"type": "text", "text": "..."}
  ],
  "stop_reason": "end_turn",
  "stop_sequence": null,
  "usage": {
    "input_tokens": 123,
    "output_tokens": 456,
    "cache_creation_input_tokens": 0,
    "cache_read_input_tokens": 0,
    "service_tier": "standard"
  }
}
```

For requests that produced an upstream error, `response_payload` carries the Anthropic error envelope:

```json
{
  "type": "error",
  "error": {
    "type": "overloaded_error",
    "message": "Overloaded"
  }
}
```

---

## Lifecycle

1. **Create.** A row in `requests` is created when the request is accepted. `request_raw` is created at the same moment with `request_payload` populated; `response_payload` is `NULL` until the upstream call completes.
2. **Update.** When the Anthropic response arrives, `request_raw.response_payload` is filled, `request_usage` is inserted, and `requests.completed_at` / `http_status` / `status` are updated. The `request_raw` write happens in its own transaction first to make "Anthropic call succeeded" observable as a row — this powers the async idempotency check (see [ADR-005](decisions.md#adr-005-no-idempotency-key-for-anthropic-messages-api)).
3. **Cleanup.** The scheduled `requests:cleanup` command (03:00 daily) deletes rows by `requests.created_at`. See "Retention policy" below.

---

## Retention policy

Cleanup runs daily via `php artisan requests:cleanup` and deletes by `requests.created_at`:

- `request_raw`: older than `raw_log_retention_days` (default 14, configurable in `config/llm.php`).
- `request_usage` and `requests`: older than `session_default_ttl_days` (default 30).
- `async_pending`: rows whose `expires_at` is older than 1 day.

For longer audit retention, increase `session_default_ttl_days` or archive rows manually before the TTL elapses.

---

## Debug access

Look up a request by id:

```sql
SELECT r.*, ru.input_tokens, ru.output_tokens, ru.cost_usd
FROM requests r
LEFT JOIN request_usage ru ON ru.request_id = r.request_id
WHERE r.request_id = 'req_...';
```

Fetch the raw payload:

```sql
SELECT request_payload, response_payload
FROM request_raw
WHERE request_id = 'req_...';
```

Per-client requests over a window:

```sql
SELECT r.request_id, r.model_snapshot, r.status, ru.cost_usd, r.created_at
FROM requests r
LEFT JOIN request_usage ru ON ru.request_id = r.request_id
WHERE r.client_id = ?
  AND r.created_at BETWEEN '2026-04-01' AND '2026-04-30'
ORDER BY r.created_at DESC;
```

---

## PII considerations

- `request_payload` and `response_payload` may contain end-user personal data passed through by the client.
- Do not copy raw payloads into logs, tickets or external systems without scrubbing PII first.
- For a GDPR erasure request, delete `request_raw` rows by `client_id` via a JOIN on `requests`. The structured rows in `requests` and `request_usage` carry no PII (only token counts, cost and metadata).
- Restrict `request_raw` access to engineers with the appropriate role.
