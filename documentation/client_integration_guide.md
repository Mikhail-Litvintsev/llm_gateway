# LLM Gateway v4 -- Integration Guide

- **Protocol version:** 4.0
- **Format:** JSON (Anthropic Messages API)
- **Provider:** Claude (Anthropic)
- **Last updated:** 2026-04-25

---

## Deviations from Anthropic Messages API

LLM Gateway is a pass-through on top of the Anthropic Messages API. Most of the schema and semantics come directly from Anthropic — consult their [official docs](https://docs.claude.com/en/api/messages) for request/response shape. This section lists only the places where the gateway **differs** from a raw Anthropic call. Everything else is identical.

### 1. Authentication

- Anthropic: `x-api-key: sk-ant-*` header.
- Gateway: `Authorization: Bearer gw_live_*` header. The gateway maps each `gw_live_*` key to a workspace-scoped Anthropic key internally; clients never see or send the `sk-ant-*` key.
- Sending `x-api-key` directly (or using a raw `sk-ant-*` with the gateway) returns:
  ```json
  {"type":"error","error":{"type":"authentication_error","message":"Missing or invalid bearer token"}}
  ```

### 2. Model identifiers

- Anthropic: accepts model snapshots (e.g. `claude-sonnet-4-6-20260101`) and short aliases.
- Gateway: accepts **only** aliases: `claude-opus`, `claude-sonnet`, `claude-haiku`. Snapshot strings are rejected with `invalid_request_error`. The gateway chooses the snapshot from `config/llm.php` → `claude.model_aliases`.

### 3. Gateway response headers

The gateway emits these metadata headers on responses. They are **not** injected into the response body. The canonical list lives in code — grep `app/Components/Delivery/{Sync,Stream}/*Responder.php` + `app/Http/Controllers/Api/V1/*.php` for `X-Gateway-` to verify:

| Header | Present on | Meaning |
|---|---|---|
| `X-Gateway-Request-Id` | All | UUID of the gateway request (distinct from Anthropic's `anthropic-request-id`). |
| `X-Gateway-Anthropic-Request-Id` | Sync, stream | Upstream request-id from Anthropic, if returned. |
| `X-Gateway-Model-Alias` | All | Alias the client sent. |
| `X-Gateway-Model-Snapshot` | All | Snapshot the gateway resolved and sent to Anthropic. |
| `X-Gateway-Cost-USD` | Sync, stream | Final cost in USD, 6 decimal places. |
| `X-Gateway-Cost-Breakdown` | Sync, stream | Base64-encoded JSON: cost split across input/output/cache/server-tools. |
| `X-Gateway-Spend-Remaining-USD` | Sync, stream | Remaining monthly spend cap after this call. |
| `X-Gateway-Estimated-Cost-USD` | Async accept, show | Pre-flight cost estimate (async has no final cost at accept time). |
| `X-Gateway-Service-Tier-Used` | Sync, stream | Service tier returned by Anthropic. |
| `X-Gateway-Cache-Hit-Tokens` | Sync, stream | Prompt-cache hit tokens, if any. |
| `X-Gateway-Warning` | As applicable | Non-fatal anomaly (e.g. `auto_resume_limit_reached` in sessions). |
| `Retry-After` | 429 | Seconds until retry is allowed. |

### 4. Gateway-specific endpoints

These endpoints do not exist in Anthropic's API. Snapshot as of 2026-04-25; verify against `routes/api.php` before integration.

| Endpoint | Method | Description |
|---|---|---|
| `/v1/messages/async` | POST | Returns 202 + `request_id`; the result is delivered later as a signed webhook. |
| `/v1/messages/{request_id}` | GET | Retrieve a stored async request's metadata and state. |
| `/v1/messages/batch` | POST | Accumulator-mode single-item submission; the gateway accumulates and flushes to Anthropic's Batch API on size/time triggers. |
| `/v1/batches/{id}/results` | GET | NDJSON-streaming results of a completed batch. |
| `/v1/sessions` | POST | Create a multi-turn session with auto-compaction. |
| `/v1/sessions/{id}` | GET / DELETE | Session metadata / deletion. |
| `/v1/sessions/{id}/messages` | GET / POST | List / append session messages. |
| `/v1/skills/*` | * | Skill manifest lifecycle. |
| `/v1/files/*` | * | File upload, list, fetch, delete (proxy + local metadata). |
| `/v1/clients/me/usage` | GET | Current client's monthly spend and request stats. |

Endpoints that mirror Anthropic 1-for-1 (only auth / headers differ — see §1 and §3): `/v1/messages`, `/v1/messages/count_tokens`, `/v1/batches` (POST/GET), `/v1/batches/{id}` (GET/DELETE), `/v1/batches/{id}/cancel`, `/v1/models`, `/v1/models/{alias}`.

### 5. Batch endpoint — poll vs accumulator

Anthropic's Batch API expects a full batch submitted in one POST. The gateway keeps that contract (`/v1/batches`) and adds **accumulator mode** (`/v1/messages/batch`): clients POST items one at a time, the gateway accumulates them and flushes to Anthropic when size or time triggers fire. See section `7. Batch API` for the full accumulator payload shape.

### 6. Error format extensions

- Anthropic error body:
  ```json
  {"type":"error","error":{"type":"<category>","message":"..."}}
  ```
- Gateway adds `gateway_request_id` for correlation:
  ```json
  {"type":"error","error":{"type":"billing_error","message":"Monthly spend cap exceeded"},"gateway_request_id":"req_..."}
  ```
- Extra categories that Anthropic does not define: `billing_error` (spend cap), `gateway_internal_error` (unexpected server-side failure). Preventive 429 (triggered before the upstream call by the Redis rate-limit snapshot) reuses the standard `rate_limit_error`; there is no dedicated `preventive_rate_limit` type today.

### 7. Webhook envelope (async mode)

Async results are delivered as a signed webhook. The body is **not** the raw Anthropic response — it is a gateway envelope:

```json
{
  "request_id": "req_...",
  "event": "message.completed",
  "anthropic_request_id": "...",
  "model_alias": "claude-sonnet",
  "model_snapshot": "claude-sonnet-4-6",
  "anthropic_response": { "...": "full Anthropic body, 1-for-1" },
  "error": null,
  "billing": {
    "cost_usd": 0.0045,
    "cost_breakdown": { "...": "..." },
    "monthly_spend_after_usd": 125.50,
    "monthly_spend_remaining_usd": 874.50
  }
}
```

On failure, `event` is `message.failed`, `anthropic_response` is absent (or `null`), and `error` is populated. Delivery headers: `X-Webhook-Signature: sha256=<hex>`, `X-Webhook-Timestamp` (unix seconds), `X-Webhook-Request-Id`, `X-Webhook-Event`. Verification: see the "Webhook verification" section below, which includes a reference freshness check.

### 8. SSE streaming

- Pass-through of Anthropic SSE events: `message_start`, `content_block_start`, `content_block_delta`, `content_block_stop`, `message_delta`, `message_stop`.
- The gateway **does not** add its own sentinel events (no `gateway_done`, no `gateway_error`). Gateway metadata (`X-Gateway-Request-Id`, `X-Gateway-Cost-USD` and the rest of §3) is emitted in HTTP response headers before the SSE stream begins.
- The last event in the stream is Anthropic's `message_stop`. The gateway closes the connection after it.

### 9. Rate-limit signalling

- Anthropic: per-response `anthropic-ratelimit-*` headers.
- Gateway: **preventive** 429 is returned *before* calling upstream when the Redis snapshot of Anthropic's rate-limit counters predicts the request would exceed limits. The error body uses the standard type:
  ```json
  {"type":"error","error":{"type":"rate_limit_error","message":"Upstream rate limit would be exceeded; retry later"}}
  ```
  with `Retry-After: <seconds>` in headers. Gateway-originated 429 and Anthropic-originated 429 share the same `rate_limit_error` type; distinguish by the absence of `anthropic-ratelimit-*` headers and the presence of a gateway-set `Retry-After` on the preventive path.

### 10. Beta headers and feature auto-wiring

- Anthropic: the client sets `anthropic-beta: ...` manually and opts each call in to beta features.
- Gateway: `FeatureDetector` inspects the payload and sets the right `anthropic-beta` combination automatically (prompt caching, extended thinking, skills, MCP, server tools, files). Clients pass a plain Anthropic payload — the gateway attaches the beta headers.

### 11. Per-client rate limiting

The gateway enforces a per-client request budget in addition to Anthropic's workspace-level limits. See [Rate limiting](#rate-limiting). 429 responses use Anthropic's standard `rate_limit_error` shape with `Retry-After` and `X-RateLimit-*` headers.

---

## 1. Quickstart (in 5 minutes)

Two ways to get started: through the Anthropic SDK (recommended) or directly over HTTP.

### Option A: Anthropic Python SDK (recommended)

```bash
pip install anthropic
```

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    messages=[
        {"role": "user", "content": "Explain Bayes' theorem in plain language."}
    ],
)

print(message.content[0].text)
```

### Option B: curl

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "messages": [
      {"role": "user", "content": "Explain Bayes theorem in plain language."}
    ]
  }'
```

Response:

```json
{
  "id": "msg_01XFDUDYJgAACzvnptvVoYEL",
  "type": "message",
  "role": "assistant",
  "model": "claude-sonnet-4-6",
  "content": [
    {
      "type": "text",
      "text": "Bayes' theorem updates the probability of a hypothesis..."
    }
  ],
  "stop_reason": "end_turn",
  "usage": {
    "input_tokens": 18,
    "output_tokens": 245,
    "cache_creation_input_tokens": 0,
    "cache_read_input_tokens": 0
  }
}
```

Response headers carry gateway metadata (see section 4).

---

## 2. Using the Anthropic SDK with this gateway

The gateway accepts requests in the Anthropic Messages API format, so the official SDKs work directly — replace `base_url` and you are done.

### Python

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

# Synchronous request
message = client.messages.create(
    model="claude-sonnet",
    max_tokens=2048,
    system="You are a helpful assistant.",
    messages=[
        {"role": "user", "content": "What is a REST API?"}
    ],
)

# Streaming
with client.messages.stream(
    model="claude-sonnet",
    max_tokens=2048,
    messages=[{"role": "user", "content": "Write a short story."}],
) as stream:
    for text in stream.text_stream:
        print(text, end="", flush=True)

# Token counting
result = client.messages.count_tokens(
    model="claude-sonnet",
    messages=[{"role": "user", "content": "Hello!"}],
)
print(result.input_tokens)

# Batches
batch = client.messages.batches.create(
    requests=[
        {
            "custom_id": "req-1",
            "params": {
                "model": "claude-sonnet",
                "max_tokens": 1024,
                "messages": [{"role": "user", "content": "Hello!"}],
            },
        }
    ]
)
```

### TypeScript

```typescript
import Anthropic from "@anthropic-ai/sdk";

const client = new Anthropic({
  apiKey: "gw_live_your_key",
  baseURL: "https://gateway.example.com/api/v1",
});

const message = await client.messages.create({
  model: "claude-sonnet",
  max_tokens: 1024,
  messages: [{ role: "user", content: "What is TypeScript?" }],
});

console.log(message.content[0].text);
```

### Supported SDK methods

| SDK method | Gateway endpoint |
|-----------|-----------------|
| `messages.create()` | `POST /api/v1/messages` |
| `messages.stream()` | `POST /api/v1/messages` (stream: true) |
| `messages.count_tokens()` | `POST /api/v1/messages/count_tokens` |
| `messages.batches.create()` | `POST /api/v1/batches` |
| `messages.batches.list()` | `GET /api/v1/batches` |
| `messages.batches.retrieve()` | `GET /api/v1/batches/{batchId}` |
| `messages.batches.results()` | `GET /api/v1/batches/{batchId}/results` |
| `messages.batches.cancel()` | `POST /api/v1/batches/{batchId}/cancel` |
| `messages.batches.delete()` | `DELETE /api/v1/batches/{batchId}` |
| `files.upload()` | `POST /api/v1/files` |
| `files.list()` | `GET /api/v1/files` |
| `files.retrieve()` | `GET /api/v1/files/{fileId}` |
| `files.delete()` | `DELETE /api/v1/files/{fileId}` |
| `models.list()` | `GET /api/v1/models` |
| `models.retrieve()` | `GET /api/v1/models/{alias}` |

### Key differences from the direct Anthropic API

**Key format.** The gateway uses `gw_live_*` keys instead of `sk-ant-*`. The key travels in the `Authorization: Bearer gw_live_...` header exactly like a regular Anthropic key.

**Models — aliases only.** The `model` field accepts only aliases: `claude-opus`, `claude-sonnet`, `claude-haiku`. Passing a snapshot name (for example `claude-sonnet-4-6`) returns a 400 error. The gateway resolves the alias to the active snapshot.

**Extra response headers.** Every response carries `X-Gateway-*` headers with metadata: cost, request id, model and cache info (see section 4). The SDK ignores them but they are accessible through the raw response object.

**Endpoints not exposed by the SDK.** The following gateway capabilities have no Anthropic API counterpart and require direct HTTP calls:

- `POST /api/v1/messages/async` — async processing with webhook
- `POST /api/v1/messages/batch` — batch accumulator
- `POST /api/v1/sessions` and all `/sessions/*` — server-side sessions
- `POST /api/v1/skills` and all `/skills/*` — skills
- `GET /api/v1/clients/me/usage` — spend report
- `GET /api/v1/messages/{requestId}` — fetch async-request result

---

## 3. Authentication

### Key format

Gateway API keys have the format `gw_live_` + 32 base62 characters:

```text
gw_live_a7Bk3mNpQrSt9uVwXyZ1c2D4e5F6g7H8
```

### Sending the key

Pass the key in the `Authorization` header:

```http
Authorization: Bearer gw_live_a7Bk3mNpQrSt9uVwXyZ1c2D4e5F6g7H8
```

All `/api/v1/*` endpoints require authentication.

### Obtaining a key

The administrator creates the key:

```bash
docker exec llm_gateway php artisan client:create my_service --rate-limit=120
```

The command prints the **API Key** and **Signing Secret** (used to verify webhook signatures). Both values appear once — store them immediately.

### Key rotation

```bash
docker exec llm_gateway php artisan client:rotate-key <client_id>
```

API key rotation is atomic: the old key stops working immediately and the new one prints to the console. The signing secret rotation supports a grace period:

```bash
docker exec llm_gateway php artisan client:rotate-secret <client_id>
```

After signing secret rotation, the old value remains accepted for `webhook.grace_period_seconds` (default 86400 seconds = 24 hours).

### Security recommendations

- Store the key in environment variables, not in source code.
- Do not pass the key in URL parameters.
- Use separate keys per environment (staging, production).
- Rotate keys regularly.
- On compromise — rotate immediately with minimal TTL.

---

## 4. Basic request POST /api/v1/messages

The gateway works as a **pure pass-through** to the Anthropic Messages API. The request body is a **bare Anthropic Message object** sent without any wrapper or modification. The gateway forwards it directly to the Anthropic API and only attaches service headers.

### Request

```http
POST /api/v1/messages
Content-Type: application/json
Authorization: Bearer gw_live_your_key
```

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "system": "You are a Python expert.",
  "messages": [
    {
      "role": "user",
      "content": "How does the GIL work in Python?"
    }
  ]
}
```

### Response (200 OK)

The body is a standard Anthropic Message object:

```json
{
  "id": "msg_01XFDUDYJgAACzvnptvVoYEL",
  "type": "message",
  "role": "assistant",
  "model": "claude-sonnet-4-6",
  "content": [
    {
      "type": "text",
      "text": "The GIL (Global Interpreter Lock) is a CPython mechanism..."
    }
  ],
  "stop_reason": "end_turn",
  "usage": {
    "input_tokens": 25,
    "output_tokens": 312,
    "cache_creation_input_tokens": 0,
    "cache_read_input_tokens": 0
  }
}
```

### X-Gateway-* response headers

Every response carries gateway metadata:

| Header | Description | Example |
|-----------|----------|--------|
| `X-Gateway-Request-Id` | Unique gateway request id | `req_abc123def456ghi789jkl012` |
| `X-Gateway-Anthropic-Request-Id` | Anthropic API request id | `req_01A2B3C4D5E6F7G8H9` |
| `X-Gateway-Model-Alias` | Model alias from the request | `claude-sonnet` |
| `X-Gateway-Model-Snapshot` | Resolved model snapshot | `claude-sonnet-4-6` |
| `X-Gateway-Cost-USD` | Request cost in USD | `0.004215` |
| `X-Gateway-Cost-Breakdown` | Cost breakdown (base64 JSON) | `eyJpbnB1dCI6MC4w...` |
| `X-Gateway-Spend-Remaining-USD` | Client budget remaining | `95.780000` or `unlimited` |
| `X-Gateway-Service-Tier-Used` | Applied service tier | `standard` |
| `X-Gateway-Cache-Hit-Tokens` | Tokens served from cache | `1500` |

The `X-Gateway-Cost-Breakdown` header contains base64-encoded JSON with the cost split:

```json
{
  "input": 0.000075,
  "output": 0.004140,
  "cache_write": 0.0,
  "cache_read": 0.0
}
```

---

## 5. Streaming

For streaming responses, set `"stream": true` in the request body. The gateway is a **pure pass-through** of the Anthropic SSE (Server-Sent Events) stream.

### Request

```json
{
  "model": "claude-sonnet",
  "max_tokens": 2048,
  "stream": true,
  "messages": [
    {"role": "user", "content": "Write a poem about programming."}
  ]
}
```

### Response format

The HTTP response has Content-Type `text/event-stream`. The stream consists of SSE events:

```text
event: message_start
data: {"type":"message_start","message":{"id":"msg_01...","type":"message","role":"assistant","model":"claude-sonnet-4-6","content":[],"stop_reason":null,"usage":{"input_tokens":15,"output_tokens":0}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"In a world"}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" of zeros and ones"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":85}}

event: message_stop
data: {"type":"message_stop"}
```

### SSE event types

| Event | Description |
|---------|----------|
| `message_start` | Start of message; carries metadata and input-token usage |
| `content_block_start` | Start of a content block (text, tool_use, thinking) |
| `content_block_delta` | Incremental content fragment |
| `content_block_stop` | End of a content block |
| `message_delta` | Final metadata (stop_reason, output usage) |
| `message_stop` | End of stream |
| `ping` | Keep-alive |
| `error` | Error during generation |

### Errors after HTTP 200

In streaming, HTTP status 200 is sent before generation begins. If an error happens mid-stream, it arrives as an SSE `error` event:

```text
event: error
data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}
```

The client must handle these errors. SDKs do this automatically by raising the matching exception.

### Streaming response headers

Headers `X-Gateway-Request-Id`, `X-Gateway-Model-Alias`, `X-Gateway-Model-Snapshot` are available in the initial HTTP response. The cost (`X-Gateway-Cost-USD`) is not available in headers for streaming because it is computed after the stream ends — use `usage` from the `message_delta` event instead.

---

## 6. Async + webhook POST /api/v1/messages/async

Async mode: the gateway accepts the request, queues it and delivers the result to the client's webhook URL.

### Request

```http
POST /api/v1/messages/async
Content-Type: application/json
Authorization: Bearer gw_live_your_key
```

```json
{
  "model": "claude-opus",
  "max_tokens": 4096,
  "messages": [
    {"role": "user", "content": "Produce a detailed analysis of microservice architecture."}
  ],
  "callback_url": "https://your-api.example.com/api/llm-callback"
}
```

**Important:** `callback_url` must be **pre-registered** in the client's whitelist (table `client_callback_urls`). A request with a non-whitelisted URL is rejected with 400 `invalid_request_error: callback_url is not whitelisted for this client`.

### Response (202 Accepted)

```json
{
  "request_id": "req_abc123def456ghi789jkl012",
  "status": "accepted",
  "estimated_cost_usd": 0.012340,
  "estimate_mode": "character_based",
  "callback_url": "https://your-api.example.com/api/llm-callback",
  "expires_at": "2026-04-15T10:00:00+00:00"
}
```

Header: `X-Gateway-Request-Id: req_abc123def456ghi789jkl012`

Async record TTL: `async.pending_ttl_days` (default 3 days).

### Fetching the result by id

Instead of waiting for the webhook, poll for status:

```http
GET /api/v1/messages/{requestId}
Authorization: Bearer gw_live_your_key
```

Response (example for a completed request):

```json
{
  "request_id": "req_abc123def456ghi789jkl012",
  "status": "completed",
  "model_alias": "claude-opus",
  "model_snapshot": "claude-opus-4-6",
  "endpoint": "messages",
  "mode": "async_callback",
  "created_at": "2026-04-12T10:00:00Z",
  "completed_at": "2026-04-12T10:00:12Z",
  "latency_ms": 12543,
  "anthropic_request_id": "req_01A2B3...",
  "anthropic_response": { ... },
  "billing": {
    "cost_usd": 0.012340,
    "cost_breakdown": { ... },
    "monthly_spend_after_usd": null
  },
  "error": null
}
```

Possible `status` values: `accepted`, `in_progress`, `completed`, `completed_disconnected`, `failed_client_error`, `failed_server_error`, `failed_callback_delivery`, `failed_validation`, `failed_auth`. To omit the payload, pass `?include_response=false`.

### Webhook delivery

The gateway delivers the result to `callback_url` as a **wrapped envelope** — the original Anthropic response wrapped together with gateway metadata:

```json
{
  "request_id": "req_abc123def456ghi789jkl012",
  "event": "message.completed",
  "anthropic_request_id": "req_01A2B3C4D5E6F7G8H9",
  "model_alias": "claude-opus",
  "model_snapshot": "claude-opus-4-6",
  "anthropic_response": {
    "id": "msg_01XFDUDYJgAACzvnptvVoYEL",
    "type": "message",
    "role": "assistant",
    "model": "claude-opus-4-6",
    "content": [
      {
        "type": "text",
        "text": "Microservice architecture..."
      }
    ],
    "stop_reason": "end_turn",
    "usage": {
      "input_tokens": 22,
      "output_tokens": 1847,
      "cache_creation_input_tokens": 0,
      "cache_read_input_tokens": 0
    }
  },
  "billing": {
    "cost_usd": 0.015430,
    "cost_breakdown": { ... },
    "monthly_spend_after_usd": 125.50,
    "monthly_spend_remaining_usd": 874.50
  }
}
```

Possible `event` values: `message.completed`, `message.failed`. On failure the body carries `error: {type, message}` instead of `anthropic_response`.

### Webhook HMAC signature

Each webhook is signed with HMAC-SHA256 using the client's signing secret. The signed string is `{timestamp}.{body}` (timestamp from the header, body — raw request bytes). Headers:

| Header | Description |
|-----------|----------|
| `X-Webhook-Signature` | `sha256={hex}` |
| `X-Webhook-Timestamp` | Unix timestamp (seconds), part of the signature |
| `X-Webhook-Request-Id` | Gateway request id (idempotency key) |
| `X-Webhook-Event` | `message.completed` or `message.failed` |

### Signature verification (Python)

```python
import hmac
import hashlib

def verify_webhook(body: bytes, timestamp: str, signature_header: str, secret: str) -> bool:
    payload = f"{timestamp}.".encode() + body
    expected = "sha256=" + hmac.new(
        secret.encode(), payload, hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, signature_header)
```

### Webhook verification with freshness check

A signed valid delivery captured on the wire can be replayed by an attacker. To block replay attacks — check the age of `X-Webhook-Timestamp`: if the signature is valid but the timestamp is older than 300 seconds (default), drop the delivery.

**Gateway-side reference implementation:** [`app/Components/Delivery/Webhook/Signer.php::verifyWithFreshness`](../app/Components/Delivery/Webhook/Signer.php).

**PHP reference (standalone):**

```php
function verifyWebhook(
    string $body,
    string $timestampHeader,
    string $signatureHeader,
    string $secret,
    int $maxAgeSeconds = 300,
): bool {
    if (! str_starts_with($signatureHeader, 'sha256=')) {
        return false;
    }
    $provided = substr($signatureHeader, 7);
    $computed = hash_hmac('sha256', $timestampHeader.'.'.$body, $secret);
    if (! hash_equals($computed, $provided)) {
        return false;
    }
    if (! ctype_digit($timestampHeader)) {
        return false;
    }
    return abs(time() - (int) $timestampHeader) <= $maxAgeSeconds;
}
```

**TypeScript reference (Node):**

```typescript
import { createHmac, timingSafeEqual } from "node:crypto";

export function verifyWebhook(
  body: string,
  timestampHeader: string,
  signatureHeader: string,
  secret: string,
  maxAgeSeconds = 300,
): boolean {
  if (!signatureHeader.startsWith("sha256=")) return false;
  const provided = Buffer.from(signatureHeader.slice(7), "hex");
  const computed = createHmac("sha256", secret)
    .update(`${timestampHeader}.${body}`)
    .digest();
  if (provided.length !== computed.length) return false;
  if (!timingSafeEqual(provided, computed)) return false;
  if (!/^\d+$/.test(timestampHeader)) return false;
  const age = Math.abs(Math.floor(Date.now() / 1000) - Number(timestampHeader));
  return age <= maxAgeSeconds;
}
```

The order of checks — signature first, then timestamp — matters: reversing it yields a timing oracle on the timestamp's age.

### Retry policy

| Parameter | Value |
|----------|----------|
| Maximum attempts | 10 |
| Strategy | Exponential backoff |
| Initial delay | 10 seconds |
| Maximum delay | 3600 seconds (1 hour) |
| Per-request timeout | 30 seconds |
| URL grace period | 86400 seconds (24 hours) |

If all attempts are exhausted, the request is marked `failed`. The result is still available via `GET /api/v1/messages/{requestId}`.

### Delivery outcomes

Each async webhook attempt resolves to one of three terminal states recorded in `async_pending.status`:

| Outcome | Trigger | Status |
|---|---|---|
| `delivered` | Client returned HTTP 2xx. | `delivered` |
| `exhausted (transient)` | `default_max_attempts` (10) transient failures consumed: 5xx, 408, 425, 429, network errors. | `failed_callback_delivery` |
| `exhausted (permanent)` | A single response with a permanent-fail status: `400, 401, 403, 404, 410, 413, 422` (configurable via `config/llm.php` → `webhook.permanent_fail_statuses`). | `failed_callback_delivery` (no further retries) |

**Client contract:**
- Return 2xx on acceptance (the body content is ignored).
- Return 5xx, 502, 503 — or simply close the connection — for transient issues. The gateway will retry with exponential backoff (10s → 20s → … → 3600s cap), up to 10 attempts.
- Any 4xx in the permanent-fail list is treated as a deliberate rejection and is not retried. This includes auth failures (`401`, `403`), payload-too-large (`413`), and validation rejections (`422`).

---

## 7. Batch API

The Batch API lets you submit up to 100 000 requests in a single call with a 50% discount on processing cost. Results are available within 24 hours.

### Two modes

**Immediate batch** (`POST /api/v1/batches`) — sends the batch to the Anthropic API immediately. Fully SDK-compatible (`client.messages.batches.create()`).

**Batch accumulator** (`POST /api/v1/messages/batch`) — items accumulate on the gateway side and are flushed when one of the triggers fires:

| Trigger | Threshold |
|---------|-------|
| Request count | 100 |
| Total size | 50 MB |
| Accumulation time | 300 seconds (5 minutes) |

### Creating an immediate batch

```bash
curl -X POST https://gateway.example.com/api/v1/batches \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "requests": [
      {
        "custom_id": "task-001",
        "params": {
          "model": "claude-sonnet",
          "max_tokens": 1024,
          "messages": [{"role": "user", "content": "Capital of France?"}]
        }
      },
      {
        "custom_id": "task-002",
        "params": {
          "model": "claude-sonnet",
          "max_tokens": 1024,
          "messages": [{"role": "user", "content": "Capital of Japan?"}]
        }
      }
    ]
  }'
```

Response:

```json
{
  "id": "bat_abc123def456ghi789jkl012",
  "type": "message_batch",
  "processing_status": "in_progress",
  "request_counts": {
    "processing": 2,
    "succeeded": 0,
    "errored": 0,
    "canceled": 0,
    "expired": 0
  },
  "created_at": "2026-04-12T10:00:00Z",
  "expires_at": "2026-04-13T10:00:00Z"
}
```

### Checking status

```bash
curl https://gateway.example.com/api/v1/batches/bat_abc123def456ghi789jkl012 \
  -H "Authorization: Bearer gw_live_your_key"
```

### Fetching results

```bash
curl https://gateway.example.com/api/v1/batches/bat_abc123def456ghi789jkl012/results \
  -H "Authorization: Bearer gw_live_your_key"
```

Result format — JSONL (one JSON object per line):

```json
{"custom_id":"task-001","result":{"type":"succeeded","message":{"id":"msg_01...","type":"message","role":"assistant","content":[{"type":"text","text":"The capital of France is Paris."}],"model":"claude-sonnet-4-6","stop_reason":"end_turn","usage":{"input_tokens":12,"output_tokens":15}}}}
{"custom_id":"task-002","result":{"type":"succeeded","message":{"id":"msg_02...","type":"message","role":"assistant","content":[{"type":"text","text":"The capital of Japan is Tokyo."}],"model":"claude-sonnet-4-6","stop_reason":"end_turn","usage":{"input_tokens":12,"output_tokens":14}}}}
```

### Batch management

```bash
# Cancel
curl -X POST https://gateway.example.com/api/v1/batches/bat_.../cancel \
  -H "Authorization: Bearer gw_live_your_key"

# Delete (after completion)
curl -X DELETE https://gateway.example.com/api/v1/batches/bat_... \
  -H "Authorization: Bearer gw_live_your_key"

# List all batches
curl https://gateway.example.com/api/v1/batches \
  -H "Authorization: Bearer gw_live_your_key"
```

### Batch pricing

Batch requests cost 50% of the regular rate:

| Model | Input (regular) | Input (batch) | Output (regular) | Output (batch) |
|--------|:---------------:|:-------------:|:----------------:|:--------------:|
| claude-opus | $5.00 | $2.50 | $25.00 | $12.50 |
| claude-sonnet | $3.00 | $1.50 | $15.00 | $7.50 |
| claude-haiku | $1.00 | $0.50 | $5.00 | $2.50 |

Prices per 1M tokens.

### Limits

- Maximum requests per batch: 100 000
- Maximum max_output_tokens per batch: 300 000 (opus, sonnet) / 64 000 (haiku)
- Result lifetime: 24 hours

---

## 8. Sessions (multi-turn)

Sessions are a server-side implementation of multi-turn dialogue. The gateway stores the message history on the server, so the client does not have to resend the full history on every call.

### When to use

- Chatbots and conversational interfaces
- Long contexts that do not fit into a single client message
- Scenarios with compaction (compression of long context)

### When NOT to use

- One-shot requests with no follow-up
- Batch processing
- Cases where the client wants full control over the history

### Creating a session

```bash
curl -X POST https://gateway.example.com/api/v1/sessions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "system": "You are a helpful programming assistant.",
    "max_tokens": 4096
  }'
```

Response:

```json
{
  "session_id": "ses_abc123def456ghi789jkl012",
  "model": "claude-sonnet",
  "created_at": "2026-04-12T10:00:00Z",
  "expires_at": "2026-05-12T10:00:00Z",
  "message_count": 0
}
```

### Sending a message into a session

```bash
curl -X POST https://gateway.example.com/api/v1/sessions/ses_abc123.../messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "content": "How do I implement the Observer pattern in Python?"
  }'
```

The gateway automatically injects the session history into the context. The response is a standard Anthropic Message object.

### Fetching the history

```bash
curl https://gateway.example.com/api/v1/sessions/ses_abc123.../messages \
  -H "Authorization: Bearer gw_live_your_key"
```

### Compaction

When the cumulative context approaches the context-window limit, the gateway applies compaction — early messages are compressed into a short summary. The client receives the `X-Gateway-Warning: auto_resume_limit_reached` header when compaction has been activated. See section 16.

### Deleting a session

```bash
curl -X DELETE https://gateway.example.com/api/v1/sessions/ses_abc123... \
  -H "Authorization: Bearer gw_live_your_key"
```

Default session lifetime: 30 days.

---

## 9. Server-side tools

The gateway supports Claude server-side tools that execute inside Anthropic. To enable a tool, add it to the request `tools` array.

### web_search

Real-time web search.

**Cost:** $10 per 1000 calls.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "web_search_20250305",
      "name": "web_search",
      "max_uses": 5
    }
  ],
  "messages": [
    {"role": "user", "content": "What is the latest AI news this week?"}
  ]
}
```

### web_fetch

Fetches the content of a web page by URL.

**Cost:** free.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "web_fetch_20250305",
      "name": "web_fetch"
    }
  ],
  "messages": [
    {"role": "user", "content": "Fetch and summarise https://example.com/article"}
  ]
}
```

### code_execution

Runs code in an isolated sandbox.

**Cost:** $0.05/hour (1550 free hours per month).

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "code_execution_20250522",
      "name": "code_execution"
    }
  ],
  "messages": [
    {"role": "user", "content": "Compute the first 20 Fibonacci numbers and plot them."}
  ]
}
```

### text_editor

Tool for editing text files (create, read, replace).

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "text_editor_20250429",
      "name": "text_editor"
    }
  ],
  "messages": [
    {"role": "user", "content": "Create a config.yaml with web-server settings."}
  ]
}
```

### bash

Runs bash commands inside a sandbox.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "bash_20250429",
      "name": "bash"
    }
  ],
  "messages": [
    {"role": "user", "content": "List files in the current directory."}
  ]
}
```

### computer_use (beta)

Drives a computer through screenshots and mouse/keyboard actions.

**Requires a beta header.** The gateway adds the required beta header automatically.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "computer_20250124",
      "name": "computer",
      "display_width_px": 1920,
      "display_height_px": 1080
    }
  ],
  "messages": [
    {"role": "user", "content": "Open a browser and navigate to example.com"}
  ]
}
```

### tool_search

Search across available tools in the catalog.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "tool_search",
      "name": "tool_search"
    }
  ],
  "messages": [
    {"role": "user", "content": "Find a tool for working with spreadsheets."}
  ]
}
```

### memory

Long-term memory tool for storing and retrieving notes between sessions.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "tools": [
    {
      "type": "memory",
      "name": "memory"
    }
  ],
  "messages": [
    {"role": "user", "content": "Remember that my project uses Python 3.12 and FastAPI."}
  ]
}
```

### Programmatic tool calling (custom tools)

Beyond server-side tools, Claude supports custom tools described with JSON Schema. Claude decides when to invoke a tool and returns a `tool_use` content block. The client executes the tool and sends a `tool_result` back.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "tools": [
    {
      "name": "get_weather",
      "description": "Returns current weather for the specified city.",
      "input_schema": {
        "type": "object",
        "properties": {
          "city": {"type": "string", "description": "City name"}
        },
        "required": ["city"]
      }
    }
  ],
  "messages": [
    {"role": "user", "content": "What is the weather in Moscow?"}
  ]
}
```

Response with `tool_use`:

```json
{
  "content": [
    {
      "type": "tool_use",
      "id": "toolu_01A2B3C4D5E6",
      "name": "get_weather",
      "input": {"city": "Moscow"}
    }
  ],
  "stop_reason": "tool_use"
}
```

The client calls its weather API and returns the result:

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "tools": [
    {
      "name": "get_weather",
      "description": "Returns current weather for the specified city.",
      "input_schema": {
        "type": "object",
        "properties": {
          "city": {"type": "string"}
        },
        "required": ["city"]
      }
    }
  ],
  "messages": [
    {"role": "user", "content": "What is the weather in Moscow?"},
    {
      "role": "assistant",
      "content": [
        {
          "type": "tool_use",
          "id": "toolu_01A2B3C4D5E6",
          "name": "get_weather",
          "input": {"city": "Moscow"}
        }
      ]
    },
    {
      "role": "user",
      "content": [
        {
          "type": "tool_result",
          "tool_use_id": "toolu_01A2B3C4D5E6",
          "content": "Moscow: +15C, cloudy, wind 5 m/s"
        }
      ]
    }
  ]
}
```

### Citations

Claude can return citations — references to source text fragments used to generate the answer. Citations are enabled automatically when documents are present in the context.

### Search result blocks (RAG)

For the RAG pattern, send search results as `search_result` content blocks. Claude uses them as context and may reference them through citations.

### Structured outputs (output_config)

To get a structured JSON response, use the `output_config` parameter with a JSON Schema:

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "output_config": {
    "schema": {
      "type": "object",
      "properties": {
        "sentiment": {"type": "string", "enum": ["positive", "negative", "neutral"]},
        "confidence": {"type": "number"}
      },
      "required": ["sentiment", "confidence"]
    }
  },
  "messages": [
    {"role": "user", "content": "Analyse sentiment: 'Excellent product, recommended!'"}
  ]
}
```

### MCP connector (beta)

See §13.3 "MCP connector".

### Inference geo

By default all requests are processed in the US region. This is configured at the gateway level.

---

## 10. Files API

The Files API lets you upload files to the server and reference them by id in subsequent requests, avoiding repeated upload of large files.

### Uploading a file

```bash
curl -X POST https://gateway.example.com/api/v1/files \
  -H "Authorization: Bearer gw_live_your_key" \
  -F "file=@document.pdf"
```

Response:

```json
{
  "id": "file_abc123def456ghi789jkl012",
  "filename": "document.pdf",
  "mime_type": "application/pdf",
  "size_bytes": 2458901,
  "created_at": "2026-04-12T10:00:00Z"
}
```

### Using the file in a request

Reference the uploaded file via a `file` content block:

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "messages": [
    {
      "role": "user",
      "content": [
        {
          "type": "file",
          "source": {
            "type": "file_id",
            "file_id": "file_abc123def456ghi789jkl012"
          }
        },
        {
          "type": "text",
          "text": "Summarise this document."
        }
      ]
    }
  ]
}
```

### Listing files

```bash
curl https://gateway.example.com/api/v1/files \
  -H "Authorization: Bearer gw_live_your_key"
```

### Deleting a file

```bash
curl -X DELETE https://gateway.example.com/api/v1/files/file_abc123... \
  -H "Authorization: Bearer gw_live_your_key"
```

### Limits

- Maximum file size: 500 MB
- Files with no references are flagged after 90 days
- Hard delete: 14 days after flagging

---

## 11. Token counting and estimation

### Counting tokens

Endpoint `POST /api/v1/messages/count_tokens` reports the input-token count before sending a request.

```bash
curl -X POST https://gateway.example.com/api/v1/messages/count_tokens \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "messages": [
      {"role": "user", "content": "Hello, how are you?"}
    ]
  }'
```

Response:

```json
{
  "input_tokens": 12
}
```

### Response headers

| Header | Description |
|-----------|----------|
| `X-Gateway-Request-Id` | Request id |
| `X-Gateway-Estimated-Cost-USD` | Estimated cost of the full request |

The cost estimate is computed as `input_tokens * input_price + (input_tokens * 0.5) * output_price`. The 0.5 multiplier is a heuristic for output-token estimation.

### Budget planning

To plan spend:

1. Call `count_tokens` with a representative request.
2. Use `X-Gateway-Estimated-Cost-USD` as the per-request cost estimate.
3. Multiply by the expected number of requests.

For actual spend, query:

```bash
curl https://gateway.example.com/api/v1/clients/me/usage \
  -H "Authorization: Bearer gw_live_your_key"
```

---

## 12. Prompt caching

Prompt caching caches part of the context between requests, saving up to 90% of input-token cost and increasing throughput up to 5x.

### How it works

Anthropic caches content marked with cache breakpoints (`cache_control`). On a follow-up request with the same prefix, cached tokens are not re-read — they are served from the cache.

### Minimum cacheable size

| Model | Minimum tokens for caching |
|--------|:-------------------------------:|
| claude-opus | 1024 |
| claude-sonnet | 1024 |
| claude-haiku | 2048 |

If content is smaller than the minimum, caching does not apply but no error is raised.

### Cache TTL

| TTL | Write price (input multiplier) | Read price (input multiplier) |
|-----|:---------------------------------:|:---------------------------------:|
| 5 minutes (default) | 1.25x | ~0.1x |
| 1 hour | ~2x | ~0.1x |

### Where to place cache_control

Cache what repeats between requests:

1. **System prompt** — the most common candidate
2. **Static tools** — they do not change between requests
3. **Documents and context** — shared context for a series of requests
4. **Start of history** — early messages in a multi-turn dialogue

Place `cache_control` at the end of the cacheable block.

### The 20-block rule

Anthropic processes `cache_control` breakpoints across the last 20 blocks marked with `cache_control`. Breakpoints beyond 20 are ignored (counting from the end).

### Automatic caching

By default the gateway enables top-level auto-caching (`auto_top_level`). The system prompt and tools receive a `cache_control` breakpoint automatically when their size exceeds the minimum threshold.

### Manual cache_control

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "system": [
    {
      "type": "text",
      "text": "You are a data analysis expert. Knowledge base: ...(long text)...",
      "cache_control": {"type": "ephemeral"}
    }
  ],
  "messages": [
    {"role": "user", "content": "Analyse the Q1 trend."}
  ]
}
```

1-hour TTL (automatic for batch requests):

```json
{
  "cache_control": {"type": "ephemeral", "ttl": "1h"}
}
```

### Anti-patterns

- Do not put `cache_control` on every block — it does not speed processing up and may slow it down.
- Do not cache content that changes per request — you pay for writes but never read from cache.
- Do not cache content smaller than the minimum size — the breakpoint is ignored.
- Mind the 20-breakpoint cap — earliest breakpoints beyond it are ignored.

### Cache-aware ITPM

When caching is active, effective ITPM (Input Tokens Per Minute) consumption on Anthropic's side drops because cached tokens are processed faster. This makes more efficient use of rate limits.

---

## 13. Advanced features

This section covers extra gateway capabilities, several of which require activation through `allowed_features`. Each subsection lists purpose, request/response shape, `allowed_features` requirements, error codes, and `curl` examples.

### 13.1 Skills API

**Purpose.** Skills — pluggable Claude "knowledge" available inside the `code_execution` tool. Only prebuilt skills are supported today (working with office formats). Custom skills are not accepted yet and return `501 custom_skills_not_yet_supported`.

**Endpoints.**

- `POST /api/v1/skills` — create a prebuilt skill for the client.
- `GET /api/v1/skills` — list active skills for the client.
- `GET /api/v1/skills/{skill_id}` — single skill card.
- `DELETE /api/v1/skills/{skill_id}` — soft-delete the skill.

The identifier has the format `skl_` + 24 `[A-Za-z0-9]` characters and is enforced in routing (`routes/api.php`, regex `skl_[A-Za-z0-9]{24}`). For authentication headers, see §3.

#### Request format `POST /api/v1/skills`

```json
{
  "type": "prebuilt",
  "name": "xlsx"
}
```

Fields:

- `type` (string, required): `"prebuilt"` is the only supported value; `"custom"` returns `501`.
- `name` (string, required for `prebuilt`): one of `xlsx`, `docx`, `pptx`, `pdf`. Full list — config `claude.skills.prebuilt`.

#### Response format `POST /api/v1/skills` (201 Created)

```json
{
  "skill_id": "skl_Ab12Cd34Ef56Gh78Ij90Kl12",
  "name": "xlsx",
  "type": "prebuilt",
  "is_prebuilt": true,
  "created_at": "2026-04-21T10:15:00+00:00"
}
```

The `version` field is **not returned** here — it is added only in `GET /skills/{id}` and `GET /skills` responses.

#### Response format `GET /api/v1/skills/{skill_id}` (200 OK)

```json
{
  "skill_id": "skl_Ab12Cd34Ef56Gh78Ij90Kl12",
  "name": "xlsx",
  "type": "prebuilt",
  "is_prebuilt": true,
  "version": 1,
  "created_at": "2026-04-21T10:15:00+00:00"
}
```

#### Response format `GET /api/v1/skills` (200 OK)

```json
{
  "data": [
    {
      "skill_id": "skl_Ab12Cd34Ef56Gh78Ij90Kl12",
      "name": "xlsx",
      "type": "prebuilt",
      "is_prebuilt": true,
      "version": 1,
      "created_at": "2026-04-21T10:15:00+00:00"
    }
  ]
}
```

#### `DELETE /api/v1/skills/{skill_id}` (204 No Content)

Deletion is soft: the record is marked `is_deleted = true` and the response body is empty. Re-creating a skill with the same `name` after deletion produces a **new** `skill_id`.

#### Using skills in `/api/v1/messages`

To use a skill in a regular request, pass an array of `skill_id` in the `skills` field and add a tool with `type` starting with `code_execution` to `tools` (the validator uses a `str_starts_with`-style check — any `tool.type` starting with `code_execution` passes the prefix check for skills). Without such a tool the gateway returns `400 skills_require_code_execution`.

In examples, use the current value `code_execution_20260120` (constant `ToolTypeCatalog::CODE_EXECUTION`). It is the only identifier that simultaneously passes the `code_execution` feature-check via exact match in `ToolTypeCatalog::FEATURE_MAP` — legacy values (such as `code_execution_20250522`) pass the prefix check for skills but fail the feature-check.

**`allowed_features` requirement.** Requires `skills: true`. **The `skills` feature is not in `ClientEnableFeature::KNOWN_FEATURES` — the CLI `php artisan client:enable-feature <id> skills` rejects the command as "Unknown feature". Activation is possible only through direct update of the JSON column `allowed_features` on the client (see §13.6).**

**Beta header.** When `payload.skills` is non-empty, the gateway adds `anthropic-beta: skills-2025-10-02` automatically (config `claude.beta_headers.skills`; logic — `PayloadBuilder::detectBetaFeatures`).

#### Skills API error codes

| HTTP | `error.type` | Condition |
|------|-------------|---------|
| 400 | `invalid_skill_type` | `POST /skills`: `type` is neither `"prebuilt"` nor `"custom"`. |
| 400 | `skill_creation_failed` | `POST /skills`: unknown prebuilt `name` (message `Unknown prebuilt skill: '<name>'`). |
| 400 | `skills_not_enabled` | `/messages` payload: `skills` is set but the client lacks the `skills` feature (check in `MessageRequestValidator`, this is no longer Skills API). |
| 400 | `skills_require_code_execution` | `/messages` payload: `skills` is set but no `tools[].type` starts with `code_execution`. |
| 403 | `skill_creation_failed` | `POST /skills` without the `skills` feature. Message — `Skills feature is not enabled for this client`. Collision with `400` — distinguish by HTTP code. |
| 403 | `skills_list_failed` | `GET /skills` without the `skills` feature. |
| 403 | `skill_delete_failed` | `DELETE /skills/{id}` without the `skills` feature. |
| 404 | `skill_not_found` | `GET /skills/{id}` / `DELETE /skills/{id}`: id is unknown, soft-deleted, or owned by another client. |
| 501 | `custom_skills_not_yet_supported` | `POST /skills`: `type: "custom"`. |

**Important note on gating.** Activation errors in Skills API are wired differently from server-tools. `SkillsOrchestrator::assertSkillsEnabled` throws a `RuntimeException` with HTTP code `403`, but the controller catches the exception itself and returns an endpoint-specific `error.type`: `POST /skills` → `skill_creation_failed`, `GET /skills` → `skills_list_failed`, `DELETE /skills/{id}` → `skill_delete_failed`. The `error.type` field in Skills API responses always comes from the table above — the "generic" validation error `skills_not_enabled` happens only in `/messages`. The `GET /skills/{id}` method **does not** call `assertSkillsEnabled`: for a client without the feature the result is `404 skill_not_found` (the skill either does not exist or belongs to another client), not `403`.

#### Restrictions and incompatibilities

- Custom skills — always `501 custom_skills_not_yet_supported`; artefact upload is not supported.
- `skills` in payload without a `code_execution*` tool — `400 skills_require_code_execution`.
- Soft-delete is irreversible at the API level: a new `POST /skills` with the same `name` creates a record with a different `skill_id` and `version: 1`.

#### `curl` examples

Create a prebuilt skill for Excel.

```bash
curl -X POST https://gateway.example.com/api/v1/skills \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{"type":"prebuilt","name":"xlsx"}'
```

Use the skill in `/api/v1/messages` together with `code_execution_20260120`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "skills": ["skl_Ab12Cd34Ef56Gh78Ij90Kl12"],
    "tools": [
      {"type": "code_execution_20260120", "name": "code_execution"}
    ],
    "messages": [
      {"role": "user", "content": "Open the attached .xlsx and sum column B."}
    ]
  }'
```

Trying to create a skill for a client without the `skills` feature returns `403`.

```bash
curl -X POST https://gateway.example.com/api/v1/skills \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{"type":"prebuilt","name":"xlsx"}'
```

```json
{
  "type": "error",
  "error": {
    "type": "skill_creation_failed",
    "message": "Skills feature is not enabled for this client"
  }
}
```

### 13.2 Fast mode

**Purpose.** Accelerated Claude Opus inference: priority handling on Anthropic's side at a higher rate. Use it where latency matters more than cost (interactive chat, interactive prompts). For `count_tokens` the mode is meaningless — the validator does not return a dedicated error, but counting tokens "faster" makes no sense; do not use `speed: "fast"` in such scenarios.

**Payload parameter.** In `POST /api/v1/messages` (and in session/async contexts using the same validator) — the `speed` field. Allowed values from enum `App\Components\Validation\Enums\Speed`:

- `"standard"` — default value;
- `"fast"` — fast mode.

Any other value → `400 speed_invalid`.

**Per-model support.** Fast mode is available only on `claude-opus`. Source — column `supports_fast_mode` in `config/llm.php → claude.model_capabilities`:

| Model | `supports_fast_mode` |
|--------|----------------------|
| `claude-opus` | `true` |
| `claude-sonnet` | `false` |
| `claude-haiku` | `false` |

Full model capabilities — see §14. Choosing a model.

**`allowed_features` requirement.** Requires `fast_mode: true`. **The CLI `php artisan client:enable-feature <id> fast_mode` does not support this feature — `fast_mode` is missing from `ClientEnableFeature::KNOWN_FEATURES`. Activation is performed via direct update of the client's JSON `allowed_features` column. Details — §13.6.**

**Incompatibilities.**

- `speed: "fast"` + `service_tier: "auto"` → `400 fast_mode_priority_incompatible` (two accelerators at once are not allowed).
- `speed: "fast"` inside Batch API (`/api/v1/batches` item payload) → `400 fast_mode_batch_incompatible`.
- `speed: "fast"` on `claude-sonnet` or `claude-haiku` → `400 fast_mode_model_unsupported`.
- `count_tokens`: the mode is semantically inapplicable; the validator does not reject it with a special error but it has no measurable effect.

**Pricing.** With `speed: "fast"` the cost of `input` and `output` tokens is multiplied by `×6.0` (`config/llm.php → claude.pricing.fast_multiplier`). Cache operations (`cache_write_5m`, `cache_write_1h`, `cache_read`) are billed at the regular rate — the multiplier **does not apply**. Base rates per 1M tokens — see §14. Choosing a model → "Cost per 1M tokens".

**Beta header.** When `payload.speed === "fast"`, the gateway adds `anthropic-beta: fast-mode-2026-02-01` automatically (`config/llm.php → claude.beta_headers.fast_mode`; logic — `PayloadBuilder::detectBetaFeatures`).

**Error codes.** Row order mirrors validator check order — the first matching error short-circuits the rest.

| HTTP | `error.type` | Trigger |
|------|-------------|---------|
| 400 | `speed_invalid` | `speed` is not in the enum (`standard` \| `fast`). |
| 400 | `fast_mode_not_enabled` | `speed: "fast"` and the client lacks the `fast_mode` feature. |
| 400 | `fast_mode_model_unsupported` | `speed: "fast"` on a model without `supports_fast_mode` (`claude-sonnet`, `claude-haiku`). |
| 400 | `fast_mode_batch_incompatible` | `speed: "fast"` inside Batch API. |
| 400 | `fast_mode_priority_incompatible` | `speed: "fast"` + `service_tier: "auto"`. |

#### `curl` examples

Successful fast-mode request on `claude-opus`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 256,
    "speed": "fast",
    "messages": [
      {"role": "user", "content": "Give a short answer: what is the CAP theorem?"}
    ]
  }'
```

The same request on `claude-sonnet` is rejected with `400 fast_mode_model_unsupported`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 256,
    "speed": "fast",
    "messages": [
      {"role": "user", "content": "Test"}
    ]
  }'
```

```json
{
  "type": "error",
  "error": {
    "type": "fast_mode_model_unsupported",
    "message": "Fast mode is not supported on model claude-sonnet"
  }
}
```

### 13.3 MCP connector

**Purpose.** Plug external Model Context Protocol (MCP) servers directly into a `/api/v1/messages` request. The client does not host the tools itself — Anthropic reaches the configured MCP server (typically SSE over HTTPS) and calls its tools on behalf of the model. Typical scenarios: corporate documentation search, CRM/ERP lookup, third-party SaaS integrations through MCP.

**Payload parameter.** The `mcp_servers` field — an array of objects describing external MCP servers. Also allowed in session/async requests that share the same validator.

**Element shape.** Example:

```json
{
  "type": "url",
  "url": "https://mcp.example.com/sse",
  "name": "docs_search",
  "authorization_token": "Bearer <token>",
  "tool_configuration": {}
}
```

| Field | Required | Type | Description |
|------|-------------|-----|----------|
| `type` | yes | string | The currently observed value is `"url"` (confirmed in `tests/Unit/Claude/MCPConnectorPayloadTest.php`). |
| `url` | yes | string | HTTPS address of the MCP server (typically an SSE endpoint). |
| `name` | yes | string | Short alias for the server. Anthropic uses it in `tool_use` block names as `<name>__<tool>`. |
| `authorization_token` | no | string | Sent in the `Authorization` header when Anthropic calls the MCP server. **Masked in gateway logs** (see "Security"). |
| `tool_configuration` | no | object | Forwarded to Anthropic unchanged; the gateway does not validate the inner structure. |

A more detailed element schema lives in Anthropic's documentation for the `mcp-client-2025-11-20` beta.

**Pairing with `tools`.** The gateway **does not require** an `mcp_toolset` marker in `tools`. According to the Anthropic contract a pattern is allowed where the client also adds `{"type": "mcp_toolset", "server_name": "<name>"}` to `tools` — when present, the gateway forwards it as is, without validation.

**Response contract.** For each `tool_use` block whose `name` has the form `<server_name>__<tool>`, the gateway adds `mcp_server_name: "<server_name>"` to the block — this lets the client tell at a glance which MCP server fired. For `tool_use` blocks without `__` in the name the field is not added. Behaviour is verified in `MCPConnectorPayloadTest::response_parser_tags_mcp_tool_use_blocks`.

**`allowed_features` requirement.** Requires `mcp_connector: true`. **The CLI `php artisan client:enable-feature <id> mcp_connector` does not support this feature — the name is missing from `ClientEnableFeature::KNOWN_FEATURES`. Activation — direct update of the client's JSON `allowed_features` column. Details — §13.6.**

**Beta header.** When `mcp_servers` is non-empty, the gateway adds `anthropic-beta: mcp-client-2025-11-20` automatically (`config/llm.php → claude.beta_headers.mcp_client`; logic — `PayloadBuilder::detectBetaFeatures` via `hasMcpServers`).

**Incompatibilities.** Inside the Batch API (`/api/v1/batches` item) MCP is not supported by Anthropic's contract. The gateway validator does not block it with a dedicated error, but `mcp_servers` should not be used inside batches — upstream behaviour is not guaranteed.

**Security.**

- `authorization_token` is **masked in gateway logs** as `[REDACTED]` via `PayloadMasker::mask`. The token is forwarded to Anthropic upstream as is — otherwise authorization on the MCP server would fail; the gateway does not strip it from the request.
- When MCP is used inside sessions, the gateway stores `authorization_token` encrypted in the database. Session integration details are out of scope for this section.

**Error codes.**

| HTTP | `error.type` | Trigger |
|------|-------------|---------|
| 400 | `mcp_connector_not_enabled` | `mcp_servers` is non-empty, the `mcp_connector` feature is off. |
| 400 | `invalid_request_error` | `mcp_servers[]` element schema violation (`type`, `url`, `name`) — message is produced by the schema validator. |

#### `curl` examples

Successful request with an MCP server attached.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "mcp_servers": [
      {
        "type": "url",
        "url": "https://mcp.example.com/sse",
        "name": "docs_search",
        "authorization_token": "Bearer mcp_secret_token"
      }
    ],
    "messages": [
      {"role": "user", "content": "Find in our documentation how webhook retry is configured."}
    ]
  }'
```

The same request for a client without the `mcp_connector` feature is rejected.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 256,
    "mcp_servers": [
      {"type": "url", "url": "https://mcp.example.com/sse", "name": "docs_search"}
    ],
    "messages": [
      {"role": "user", "content": "Test"}
    ]
  }'
```

```json
{
  "type": "error",
  "error": {
    "type": "mcp_connector_not_enabled",
    "message": "MCP connector is not enabled for this client"
  }
}
```

### 13.4 Service tier

**Purpose.** Pick Anthropic's priority throughput pool when available. Typical scenario — critical production traffic where queue latency matters more than cost.

**Payload parameter.** The `service_tier` field in `POST /api/v1/messages`. Allowed values — enum `App\Components\Validation\Enums\ServiceTier`:

- `"standard_only"` — default value (`config/llm.php → claude.service_tier.default`);
- `"auto"` — request priority with transparent fallback to standard if the priority pool is unavailable.

**Important.** Any other value — including the string `"priority"` — is rejected by the validator with `400 service_tier_invalid`. The string `"priority"` appears only as a value of the response header (see below) and is **not a valid client-side input**.

**`allowed_features` requirement.** No activation needed for `standard_only`. For `"auto"` — the `priority_tier: true` feature is required. Unlike `skills` / `fast_mode` / `mcp_connector`, the `priority_tier` feature is in `ClientEnableFeature::KNOWN_FEATURES`, so it can be enabled with the standard CLI:

```bash
php artisan client:enable-feature <client_id> priority_tier
```

Details — §13.6.

**Response header `X-Gateway-Service-Tier-Used`.** Set by the gateway in sync responses (`SyncResponder`) to the value Anthropic returned. Possible states:

| Value | Meaning |
|----------|-------|
| `standard` | Anthropic applied the standard tier (including fallback under `auto`). |
| `priority` | Anthropic applied priority. |
| *(absent)* | The upstream response has no `service_tier` field (for example in some streaming chunks). |

**Pricing.** When `X-Gateway-Service-Tier-Used === "priority"`, input and output token costs are multiplied by `config/llm.php → claude.service_tier.priority_multiplier` (currently `1.0`, i.e. no surcharge; the value may change in the future). The multiplier **does not apply** to cache tokens. Base rates per 1M tokens — see §14. Choosing a model → "Cost per 1M tokens".

**Incompatibilities.**

- `service_tier: "auto"` + `speed: "fast"` → `400 fast_mode_priority_incompatible` (see §13.2).
- Batch context: the gateway **does not block** `service_tier: "auto"` inside a batch item, but the actual applicability of priority in Batch API is governed by Anthropic's contract. In practice, do not rely on priority in batch pipelines — see Anthropic Batch API documentation.

**Beta header.** This feature does not need a beta header and the gateway does not add one — `service_tier` is a first-class Anthropic API parameter.

**Error codes.**

| HTTP | `error.type` | Trigger |
|------|-------------|---------|
| 400 | `service_tier_invalid` | `service_tier` value is not in the enum (`standard_only`, `auto`); the string `"priority"` falls here too. |
| 400 | `priority_tier_not_enabled` | `service_tier: "auto"` while the `priority_tier` feature is off. |
| 400 | `fast_mode_priority_incompatible` | `service_tier: "auto"` + `speed: "fast"` (see §13.2). |

#### `curl` examples

Successful priority request on `claude-opus`. Expect `X-Gateway-Service-Tier-Used: priority` (or `standard` on fallback) in the response.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -i \
  -d '{
    "model": "claude-opus",
    "max_tokens": 512,
    "service_tier": "auto",
    "messages": [
      {"role": "user", "content": "Briefly describe a retry strategy for idempotent operations."}
    ]
  }'
```

The same request for a client without the `priority_tier` feature — `400 priority_tier_not_enabled`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 256,
    "service_tier": "auto",
    "messages": [
      {"role": "user", "content": "Test"}
    ]
  }'
```

```json
{
  "type": "error",
  "error": {
    "type": "priority_tier_not_enabled",
    "message": "Priority Tier feature is not enabled for this client"
  }
}
```

### 13.5 Output 300k (`max_tokens` > 64 000)

**Purpose.** Request a large output from Claude: long reports, multi-document summarisation, code generation for large files, long batch pipelines.

**Payload parameter.** **No dedicated parameter.** The feature engages automatically once `max_tokens > 64 000` — the gateway adds the matching beta header. In the payload, just set the desired `max_tokens`.

**Per-model limits.** Values come from `config/llm.php → claude.model_capabilities` (`max_output` / `max_output_batch`).

| Model | Sync `max_output` | Batch `max_output_batch` |
|--------|:-----------------:|:------------------------:|
| `claude-opus` | 128K | 300K |
| `claude-sonnet` | 64K | 300K |
| `claude-haiku` | 64K | 64K |

**`allowed_features` requirement.** Not required: this is a model capability, not a client feature. `allowed_features` is not consulted for `max_tokens` validation.

**Beta header.** When `max_tokens > 64 000`, the gateway adds `anthropic-beta: output-300k-2026-03-24` automatically (`config/llm.php → claude.beta_headers.output_300k`; logic — `PayloadBuilder::detectBetaFeatures`).

**Validation.**

- **Sync / sync streaming / async / sessions.** When `max_tokens` exceeds the model's `max_output`, `PayloadBuilder::enforceMaxTokensCap` throws `PayloadBuildException::invalidRequest`, rendered as `400 invalid_request_error` with a message of the form `max_tokens (N) exceeds model <alias> maximum output (M)`. There is no specialised `error.type` for it — it is a regular `invalid_request_error`, the specifics live in `message`.
- **Batch.** When `max_tokens` exceeds the model's `max_output_batch`, `MessageRequestValidator::contextRulesCheck` adds the error `max_tokens_exceeds_batch_limit` with the message `max_tokens N exceeds batch limit M for <alias>`, HTTP 400.

**Incompatibilities.**

- `claude-haiku` does not support output > 64K even in batch (overall limit 64 000).
- `claude-sonnet` in sync context is capped at 64K — large output on Sonnet is available only via Batch API (limit 300K).

**Error codes.**

| HTTP | `error.type` | Context | Trigger |
|------|-------------|----------|---------|
| 400 | `invalid_request_error` (no specialised type) | sync / stream / async / session | `max_tokens` > model `max_output`. |
| 400 | `max_tokens_exceeds_batch_limit` | batch | `max_tokens` > model `max_output_batch`. |

#### `curl` examples

Successful sync request on `claude-opus` with up to 120 000 output tokens.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 120000,
    "messages": [
      {"role": "user", "content": "Generate a detailed technical report of about 100K tokens."}
    ]
  }'
```

Successful batch request on `claude-sonnet` with items up to 250 000 tokens (full batch envelope structure — see §7. Batch API).

```bash
curl -X POST https://gateway.example.com/api/v1/batches \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "requests": [
      {
        "custom_id": "report-1",
        "params": {
          "model": "claude-sonnet",
          "max_tokens": 250000,
          "messages": [
            {"role": "user", "content": "Build a multi-document report..."}
          ]
        }
      }
    ]
  }'
```

Failed sync request on `claude-haiku` with `max_tokens: 100000` (limit 64K).

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-haiku",
    "max_tokens": 100000,
    "messages": [
      {"role": "user", "content": "Test"}
    ]
  }'
```

```json
{
  "type": "error",
  "error": {
    "type": "invalid_request_error",
    "message": "max_tokens (100000) exceeds model claude-haiku maximum output (64000)"
  }
}
```

### 13.6 `allowed_features` reference

**Concept.** `allowed_features` is a JSON column on the `clients` table, a dictionary of `<feature_key> → bool`. It defines which gated capabilities are permitted for a specific client. The same column may host special keys (for example `models` — alias whitelist), but this section is limited to the feature-flag dimension.

#### Enable/disable through CLI

```bash
php artisan client:enable-feature {client_id} {feature}
php artisan client:disable-feature {client_id} {feature}
```

Both commands recognise exactly 11 keys (order — as in `ClientEnableFeature::KNOWN_FEATURES`):

`thinking`, `web_search`, `code_execution`, `computer_use`, `bash`, `text_editor`, `priority_tier`, `citations`, `prompt_caching`, `structured_outputs`, `batch`.

Any other name is rejected with `Unknown feature` and a FAILURE exit code.

#### Features outside CLI

**The following feature keys are checked by the gateway at runtime, but the current `client:enable-feature` / `client:disable-feature` commands do not know them — they are enabled only by direct update of the JSON `allowed_features` column on the client record (via `php artisan tinker` or an SQL migration): `mcp_connector`, `fast_mode`, `skills`, `web_fetch`, `tool_search`, `memory`, `inference_geo_override`, `allow_raw_anthropic_file_ids`.**

Example of enabling a feature through `tinker`:

```bash
php artisan tinker
```

```php
$client = \App\Models\Client::find($clientId);
$client->update([
    'allowed_features' => array_merge($client->allowed_features ?? [], [
        'fast_mode' => true,
    ]),
]);
```

#### Allowed by default

`Authorization::DEFAULT_ALLOW_FEATURES` implicitly enables two features when the key is missing from `allowed_features`:

- `prompt_caching`
- `citations`

For all other keys, missing value = deny.

#### Summary table

| Key | Purpose | How to enable | Error when missing |
|------|----------|-------------|----------------------|
| `thinking` | Extended/adaptive thinking (§15) | CLI | `403 permission_error` |
| `web_search` | Server-tool `web_search` (§9) | CLI | `403 permission_error` |
| `web_fetch` | Server-tool `web_fetch` (§9) | direct update of `allowed_features` | `403 permission_error` |
| `code_execution` | Server-tool `code_execution*` (§9) | CLI | `403 permission_error`; exceeding free-hours — `429 quota_exhausted` |
| `computer_use` | Server-tool `computer` (§9) | CLI | `403 permission_error` |
| `bash` | Server-tool `bash` (§9) | CLI | `403 permission_error` |
| `text_editor` | Server-tool `text_editor` (§9) | CLI | `403 permission_error` |
| `tool_search` | Server-tool `tool_search_*` (§9) | direct update of `allowed_features` | `403 permission_error` |
| `memory` | Server-tool `memory` (§9) | direct update of `allowed_features` | `403 permission_error` |
| `priority_tier` | `service_tier: "auto"` (§13.4) | CLI | `400 priority_tier_not_enabled` |
| `fast_mode` | `speed: "fast"` (§13.2) | direct update of `allowed_features` | `400 fast_mode_not_enabled` |
| `mcp_connector` | `mcp_servers` (§13.3) | direct update of `allowed_features` | `400 mcp_connector_not_enabled` |
| `skills` | `/api/v1/skills/*` and `skills` field in `/messages` (§13.1) | direct update of `allowed_features` | Skills API: `POST /skills` → `403 skill_creation_failed`, `GET /skills` → `403 skills_list_failed`, `DELETE /skills/{id}` → `403 skill_delete_failed`; `GET /skills/{id}` does not check the feature and returns `404 skill_not_found` for an unknown id; `/messages` payload: `400 skills_not_enabled` |
| `citations` | Citations in responses | CLI (enabled by default; disable with `disable-feature`) | `403 permission_error` |
| `prompt_caching` | Prompt caching (§12) | CLI (enabled by default; disable with `disable-feature`) | — (no explicit error; cache simply does not activate) |
| `structured_outputs` | `output_config` with JSON Schema | CLI | `403 permission_error` |
| `batch` | Access to Batch API (§7) | CLI | `403 permission_error` |
| `inference_geo_override` | Override `inference_geo` in client payload | direct update of `allowed_features` | `400 invalid_request_error` (inference_geo violation in `PayloadBuilder`) |
| `allow_raw_anthropic_file_ids` | Use Anthropic file_id directly, bypassing gateway files | direct update of `allowed_features` | File source resolution error: `Unknown file ID format. Use gateway file IDs or enable allow_raw_anthropic_file_ids.` |

#### Three error branches when a feature is missing

- **Server-tool feature check.** `ServerFeaturesRule` → `FeatureNotAllowedException` → `bootstrap/app.php` → HTTP 403 `permission_error`. Applies to server-side tools from §9.
- **Payload-level validator check.** `MessageRequestValidator::phase4Rules` produces a `ValidationError` for payload fields `priority_tier`, `fast_mode`, `mcp_connector`, `skills` → HTTP 400 `invalid_request_error` with a specific `error.type` (`priority_tier_not_enabled`, `fast_mode_not_enabled`, `mcp_connector_not_enabled`, `skills_not_enabled`).
- **Skills API enablement check.** `SkillsOrchestrator::assertSkillsEnabled` throws a `RuntimeException` with code 403, but **`SkillsController` catches the exception itself** and returns HTTP 403 with an endpoint-specific `error.type`: `skill_creation_failed` (`POST /skills`), `skills_list_failed` (`GET /skills`), `skill_delete_failed` (`DELETE /skills/{id}`). This is **neither `invalid_request_error` nor `permission_error`** — the exception never reaches the global renderer in `bootstrap/app.php`. Carve-out: `GET /skills/{id}` is not part of this branch — `show` does not call `assertSkillsEnabled`, and for a client without the feature the result is `404 skill_not_found`, not 403.

#### `code_execution` quota

In addition to the regular deny check, the `code_execution` server tool has a "free hours per month" cap — `config/llm.php → claude.pricing.server_tools.code_execution_free_hours_per_month` (currently `1550`). When the quota is exhausted, `CodeExecutionUsageTracker` raises `FeatureQuotaExhaustedException`, rendered as `429 quota_exhausted`.

---

## 14. Choosing a model

### Model comparison

| Capability | claude-opus | claude-sonnet | claude-haiku |
|---------------|:-----------:|:-------------:|:------------:|
| Snapshot | claude-opus-4-6 | claude-sonnet-4-6 | claude-haiku-4-5 |
| Context window | 1M | 1M | 200K |
| Max output | 128K | 64K | 64K |
| Max output (batch) | 300K | 300K | 64K |
| Adaptive thinking | yes | yes | no |
| Compaction | yes | yes | no |
| Prefill | no | yes | yes |
| Fast mode | yes | no | no |
| Min cache tokens | 1024 | 1024 | 2048 |

### Cost per 1M tokens

| | Input | Output | Cache write (5m) | Cache write (1h) | Cache read | Batch input | Batch output |
|-|:-----:|:------:|:----------------:|:----------------:|:----------:|:-----------:|:------------:|
| opus | $5.00 | $25.00 | $6.25 | $10.00 | $0.50 | $2.50 | $12.50 |
| sonnet | $3.00 | $15.00 | $3.75 | $6.00 | $0.30 | $1.50 | $7.50 |
| haiku | $1.00 | $5.00 | $1.25 | $2.00 | $0.10 | $0.50 | $2.50 |

Fast mode (opus only): 6.0x multiplier on cost.

### Selection guidance

**claude-opus** — complex tasks: research, deep analysis, creative writing, jobs that need long reasoning. Maximum output volume. Fast mode for priority handling.

**claude-sonnet** — best price/quality trade-off. Programming, summarisation, analytics, chatbots. Default model.

**claude-haiku** — fast, cheap tasks: classification, data extraction, simple answers, filtering. 200K context.

---

## 15. Adaptive thinking

Adaptive thinking (extended thinking) lets the model "think" before answering, emitting a separate reasoning block.

### Supported models

- claude-opus
- claude-sonnet

claude-haiku does NOT support adaptive thinking.

### Effort levels

| Level | Description |
|---------|----------|
| `low` | Minimal reasoning, fast answers |
| `medium` | Moderate reasoning (default) |
| `high` | Deep reasoning for complex tasks |

### Activation

```json
{
  "model": "claude-sonnet",
  "max_tokens": 16000,
  "thinking": {
    "type": "enabled",
    "budget_tokens": 10000
  },
  "messages": [
    {"role": "user", "content": "Solve this math problem: ..."}
  ]
}
```

### Response with thinking

```json
{
  "content": [
    {
      "type": "thinking",
      "thinking": "Break the problem down step by step..."
    },
    {
      "type": "text",
      "text": "Answer: 42. Here is the reasoning..."
    }
  ]
}
```

The `thinking` block carries the model's internal reasoning. Thinking tokens are counted as output.

### Streaming thinking

With `stream: true`, thinking blocks are delivered through `content_block_start` and `content_block_delta` events with `type: "thinking"`.

---

## 16. Compaction for long sessions

Compaction is automatic compression of dialogue history as it approaches the context-window limit.

### Supported models

- claude-opus
- claude-sonnet

claude-haiku does NOT support compaction.

### How it works

When the cumulative context approaches the window limit, the gateway triggers compaction:

1. Early messages are condensed into a short summary.
2. The summary replaces the original messages at the start of the context.
3. Recent messages stay untouched.

### What the client sees

When using sessions, the client receives `X-Gateway-Warning: auto_resume_limit_reached` to signal compaction. The response itself is standard — compaction is transparent to the client.

### Activation through API

Compaction can be requested explicitly through a beta header:

```json
{
  "model": "claude-sonnet",
  "max_tokens": 4096,
  "context_management": {
    "type": "enabled",
    "strategy": "compact"
  },
  "messages": [...]
}
```

The gateway adds the necessary beta headers for compaction automatically.

---

## 17. Error handling

Gateway error format is fully compatible with the Anthropic API. SDK error classes (`anthropic.BadRequestError`, `anthropic.AuthenticationError`, etc.) work as is.

### Error format

```json
{
  "type": "error",
  "error": {
    "type": "invalid_request_error",
    "message": "model: Snapshot names are not accepted. Use an alias: claude-opus, claude-sonnet, claude-haiku"
  }
}
```

### Error codes

| HTTP | Type | Description | Recommendation |
|:----:|-----|----------|-------------|
| 400 | `invalid_request_error` | Invalid request (format, parameters, snapshot model name) | Fix the request |
| 401 | `authentication_error` | Invalid or missing API key | Verify the key |
| 402 | `billing_error` | Budget exhausted | Top up the budget |
| 403 | `permission_error` | No access to the resource | Check permissions |
| 404 | `not_found_error` | Resource not found | Verify the id |
| 429 | `rate_limit_error` | Rate limit exceeded | Wait and retry |
| 500 | `api_error` | Internal gateway error | Retry later |
| 502 | `api_error` | Upstream (Anthropic) error | Retry later |
| 503 | `overloaded_error` | Service overloaded | Exponential backoff |
| 504 | `timeout_error` | Request timeout | Retry or reduce max_tokens |

### Retry guidance

| Code | Retry | Strategy |
|:---:|:-----:|-----------|
| 400 | no | Bad request |
| 401 | no | Authentication issue |
| 402 | no | Billing issue |
| 429 | yes | Exponential backoff, honour `retry-after` |
| 500 | yes | Up to 3 attempts with backoff |
| 502 | yes | Up to 3 attempts with backoff |
| 503 | yes | Exponential backoff, start at 5 seconds |
| 504 | yes | Increase timeout or shrink the task |

### Gateway 529 retry behaviour

The gateway transparently retries `529 overloaded_error` responses from Anthropic. Clients never see HTTP 529 unless Anthropic remains overloaded after `CLAUDE_HTTP_RETRY_MAX_ATTEMPTS` attempts (default 3); in that case the response surfaces as a transient failure with `error.type=api_error` and `Retry-After` set to the upstream value.

### SDK compatibility

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

try:
    message = client.messages.create(
        model="claude-sonnet",
        max_tokens=1024,
        messages=[{"role": "user", "content": "Hello!"}],
    )
except anthropic.BadRequestError as e:
    print(f"Bad request: {e.message}")
except anthropic.AuthenticationError:
    print("Check the API key")
except anthropic.RateLimitError:
    print("Rate limit, retry later")
except anthropic.APIStatusError as e:
    print(f"API error: {e.status_code} {e.message}")
```

---

## Rate limiting

The gateway enforces a per-client request budget in addition to Anthropic's workspace-level limits. The budget is independent per client — one client's traffic never affects another's.

### Defaults and overrides
- Default budget: **600 requests per minute per client**, configured by `GATEWAY_DEFAULT_RATE_LIMIT_PER_MINUTE` (see `config/llm.php` → `rate_limit.default_per_minute`).
- Per-client override: administrators set `clients.rate_limit_rpm` via `client:create --rate-limit=<rpm>` or by direct DB update.
- Bucket key: `client:<id>` — IP address is irrelevant.

### Response headers on every `/v1/*` call
| Header | Meaning |
|---|---|
| `X-RateLimit-Limit` | Maximum requests per minute for this client. |
| `X-RateLimit-Remaining` | Requests left in the current window. |
| `Retry-After` | Seconds to wait before the next request (only on 429). |

### 429 response body
```json
{
  "error": {
    "type": "rate_limit_error",
    "message": "Request rate limit exceeded. Retry after the time indicated by the Retry-After header."
  }
}
```

### Recommendation
Back off using the `Retry-After` value. Do not hard-code retry intervals — the value reflects actual remaining window time.

---

## 18. Anthropic rate limits

### Limit tiers

The gateway also surfaces Anthropic-side limits. Per-client RPM (requests per minute) is set at client creation (default 60).

### Rate-limit headers

The gateway forwards Anthropic's standard rate-limit headers:

| Header | Description |
|-----------|----------|
| `anthropic-ratelimit-requests-limit` | Request limit |
| `anthropic-ratelimit-requests-remaining` | Requests remaining |
| `anthropic-ratelimit-requests-reset` | Reset time |
| `anthropic-ratelimit-tokens-limit` | Token limit |
| `anthropic-ratelimit-tokens-remaining` | Tokens remaining |
| `anthropic-ratelimit-tokens-reset` | Token reset time |

The gateway adds:

| Header | Description |
|-----------|----------|
| `X-Gateway-Spend-Remaining-USD` | Remaining budget in USD (or `unlimited`) |

### Handling 429

When you receive HTTP 429:

1. Read the `retry-after` header (if present) — seconds until a retry is allowed.
2. If `retry-after` is absent, use exponential backoff: 1s, 2s, 4s, 8s, 16s.
3. The SDK handles retries for 429 automatically (configurable through `max_retries`).

```python
client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
    max_retries=3,
)
```

### ITPM / OTPM

Input Tokens Per Minute (ITPM) and Output Tokens Per Minute (OTPM) are controlled by Anthropic. When exceeded, Anthropic returns 429 and the gateway forwards it to the client. Prompt caching reduces effective ITPM consumption.

---

## 19. Full examples

### 18.1. Simple text request

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    messages=[
        {"role": "user", "content": "Explain the difference between TCP and UDP."}
    ],
)

print(message.content[0].text)
print(f"Tokens: {message.usage.input_tokens} in, {message.usage.output_tokens} out")
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "messages": [
      {"role": "user", "content": "Explain the difference between TCP and UDP."}
    ]
  }'
```

---

### 18.2. Streaming

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

with client.messages.stream(
    model="claude-sonnet",
    max_tokens=2048,
    messages=[
        {"role": "user", "content": "Write a step-by-step guide to configuring Nginx."}
    ],
) as stream:
    for text in stream.text_stream:
        print(text, end="", flush=True)
print()
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -N \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 2048,
    "stream": true,
    "messages": [
      {"role": "user", "content": "Write a step-by-step guide to configuring Nginx."}
    ]
  }'
```

---

### 18.3. Vision (image URL)

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    messages=[
        {
            "role": "user",
            "content": [
                {
                    "type": "image",
                    "source": {
                        "type": "url",
                        "url": "https://example.com/chart.png",
                    },
                },
                {
                    "type": "text",
                    "text": "Describe what is on the chart.",
                },
            ],
        }
    ],
)

print(message.content[0].text)
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "messages": [
      {
        "role": "user",
        "content": [
          {
            "type": "image",
            "source": {
              "type": "url",
              "url": "https://example.com/chart.png"
            }
          },
          {
            "type": "text",
            "text": "Describe what is on the chart."
          }
        ]
      }
    ]
  }'
```

---

### 18.4. Vision (Files API)

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

# Upload the file
with open("screenshot.png", "rb") as f:
    uploaded = client.files.upload(file=f)

# Use it in a request
message = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    messages=[
        {
            "role": "user",
            "content": [
                {
                    "type": "file",
                    "source": {
                        "type": "file_id",
                        "file_id": uploaded.id,
                    },
                },
                {
                    "type": "text",
                    "text": "What is on the screenshot?",
                },
            ],
        }
    ],
)

print(message.content[0].text)
```

**curl:**

```bash
# Upload the file
FILE_ID=$(curl -s -X POST https://gateway.example.com/api/v1/files \
  -H "Authorization: Bearer gw_live_your_key" \
  -F "file=@screenshot.png" | jq -r '.id')

# Use it in a request
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "messages": [
      {
        "role": "user",
        "content": [
          {
            "type": "file",
            "source": {
              "type": "file_id",
              "file_id": "'"$FILE_ID"'"
            }
          },
          {
            "type": "text",
            "text": "What is on the screenshot?"
          }
        ]
      }
    ]
  }'
```

---

### 18.5. PDF document

**Python SDK:**

```python
import anthropic
import base64

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

with open("report.pdf", "rb") as f:
    pdf_data = base64.standard_b64encode(f.read()).decode("utf-8")

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=4096,
    messages=[
        {
            "role": "user",
            "content": [
                {
                    "type": "document",
                    "source": {
                        "type": "base64",
                        "media_type": "application/pdf",
                        "data": pdf_data,
                    },
                },
                {
                    "type": "text",
                    "text": "Summarise the key takeaways from this report.",
                },
            ],
        }
    ],
)

print(message.content[0].text)
```

**curl:**

```bash
PDF_BASE64=$(base64 -w 0 report.pdf)

curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 4096,
    "messages": [
      {
        "role": "user",
        "content": [
          {
            "type": "document",
            "source": {
              "type": "base64",
              "media_type": "application/pdf",
              "data": "'"$PDF_BASE64"'"
            }
          },
          {
            "type": "text",
            "text": "Summarise the key takeaways from this report."
          }
        ]
      }
    ]
  }'
```

---

### 18.6. Custom tool use

**Python SDK:**

```python
import anthropic
import json

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

tools = [
    {
        "name": "get_stock_price",
        "description": "Returns the current stock price by ticker.",
        "input_schema": {
            "type": "object",
            "properties": {
                "ticker": {"type": "string", "description": "Stock ticker (e.g. AAPL)"},
            },
            "required": ["ticker"],
        },
    }
]

messages = [{"role": "user", "content": "What is Apple's current stock price?"}]

response = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    tools=tools,
    messages=messages,
)

if response.stop_reason == "tool_use":
    tool_block = next(b for b in response.content if b.type == "tool_use")
    print(f"Tool call: {tool_block.name}({json.dumps(tool_block.input)})")

    # Client invokes its API and returns the result
    messages.append({"role": "assistant", "content": response.content})
    messages.append({
        "role": "user",
        "content": [
            {
                "type": "tool_result",
                "tool_use_id": tool_block.id,
                "content": "AAPL: $185.50 (+1.2%)",
            }
        ],
    })

    final = client.messages.create(
        model="claude-sonnet",
        max_tokens=1024,
        tools=tools,
        messages=messages,
    )
    print(final.content[0].text)
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "tools": [
      {
        "name": "get_stock_price",
        "description": "Returns the current stock price by ticker.",
        "input_schema": {
          "type": "object",
          "properties": {
            "ticker": {"type": "string", "description": "Stock ticker"}
          },
          "required": ["ticker"]
        }
      }
    ],
    "messages": [
      {"role": "user", "content": "What is Apple stock price?"}
    ]
  }'
```

---

### 18.7. Web search tool

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=4096,
    tools=[
        {
            "type": "web_search_20250305",
            "name": "web_search",
            "max_uses": 3,
        }
    ],
    messages=[
        {"role": "user", "content": "What is the latest news about quantum computers?"}
    ],
)

for block in message.content:
    if hasattr(block, "text"):
        print(block.text)
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 4096,
    "tools": [
      {
        "type": "web_search_20250305",
        "name": "web_search",
        "max_uses": 3
      }
    ],
    "messages": [
      {"role": "user", "content": "What is the latest news about quantum computers?"}
    ]
  }'
```

---

### 18.8. Prompt caching (manual breakpoint)

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

long_system = "You are an expert lawyer. Full text of the statute: " + "..." * 500

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=2048,
    system=[
        {
            "type": "text",
            "text": long_system,
            "cache_control": {"type": "ephemeral"},
        }
    ],
    messages=[
        {"role": "user", "content": "What penalties does article 12 prescribe?"}
    ],
)

print(message.content[0].text)
print(f"Cache created: {message.usage.cache_creation_input_tokens}")
print(f"Cache read: {message.usage.cache_read_input_tokens}")
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 2048,
    "system": [
      {
        "type": "text",
        "text": "You are an expert lawyer. Full text of the statute: ...(long text)...",
        "cache_control": {"type": "ephemeral"}
      }
    ],
    "messages": [
      {"role": "user", "content": "What penalties does article 12 prescribe?"}
    ]
  }'
```

---

### 18.9. Token counting

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

result = client.messages.count_tokens(
    model="claude-sonnet",
    messages=[
        {
            "role": "user",
            "content": "Write a detailed overview of microservice architecture.",
        }
    ],
)

print(f"Input tokens: {result.input_tokens}")
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages/count_tokens \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-sonnet",
    "messages": [
      {"role": "user", "content": "Write a detailed overview of microservice architecture."}
    ]
  }'
```

---

### 18.10. Batch submission

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_your_key",
    base_url="https://gateway.example.com/api/v1",
)

batch = client.messages.batches.create(
    requests=[
        {
            "custom_id": f"translate-{i}",
            "params": {
                "model": "claude-haiku",
                "max_tokens": 512,
                "messages": [
                    {"role": "user", "content": f"Translate to English: '{text}'"}
                ],
            },
        }
        for i, text in enumerate([
            "Hello, world!",
            "How are you?",
            "Thanks for your help.",
            "Goodbye.",
        ])
    ]
)

print(f"Batch ID: {batch.id}")
print(f"Status: {batch.processing_status}")

# Wait for results (polling)
import time
while True:
    status = client.messages.batches.retrieve(batch.id)
    if status.processing_status == "ended":
        break
    time.sleep(10)

# Fetch results
for result in client.messages.batches.results(batch.id):
    print(f"{result.custom_id}: {result.result.message.content[0].text}")
```

**curl:**

```bash
# Create batch
curl -X POST https://gateway.example.com/api/v1/batches \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "requests": [
      {
        "custom_id": "translate-0",
        "params": {
          "model": "claude-haiku",
          "max_tokens": 512,
          "messages": [{"role": "user", "content": "Translate to English: Hello, world!"}]
        }
      },
      {
        "custom_id": "translate-1",
        "params": {
          "model": "claude-haiku",
          "max_tokens": 512,
          "messages": [{"role": "user", "content": "Translate to English: How are you?"}]
        }
      }
    ]
  }'

# Check status
curl https://gateway.example.com/api/v1/batches/bat_ID \
  -H "Authorization: Bearer gw_live_your_key"

# Fetch results
curl https://gateway.example.com/api/v1/batches/bat_ID/results \
  -H "Authorization: Bearer gw_live_your_key"
```

---

### 18.11. Session-based chat

**Python SDK:**

```python
import httpx

BASE = "https://gateway.example.com/api/v1"
HEADERS = {
    "Authorization": "Bearer gw_live_your_key",
    "Content-Type": "application/json",
}

# Create the session
resp = httpx.post(f"{BASE}/sessions", headers=HEADERS, json={
    "model": "claude-sonnet",
    "system": "You are a Python programming assistant.",
    "max_tokens": 4096,
})
session = resp.json()
session_id = session["session_id"]
print(f"Session: {session_id}")

# First message
resp = httpx.post(f"{BASE}/sessions/{session_id}/messages", headers=HEADERS, json={
    "content": "How do I implement a singleton in Python?",
})
print(resp.json()["content"][0]["text"])

# Second message (context retained)
resp = httpx.post(f"{BASE}/sessions/{session_id}/messages", headers=HEADERS, json={
    "content": "Now show a thread-safe variant.",
})
print(resp.json()["content"][0]["text"])

# Session history
resp = httpx.get(f"{BASE}/sessions/{session_id}/messages", headers=HEADERS)
for msg in resp.json():
    print(f"[{msg['role']}]: {msg['content'][:80]}...")
```

**curl:**

```bash
# Create the session
SESSION_ID=$(curl -s -X POST https://gateway.example.com/api/v1/sessions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{"model": "claude-sonnet", "system": "You are an assistant.", "max_tokens": 4096}' \
  | jq -r '.session_id')

# First message
curl -X POST "https://gateway.example.com/api/v1/sessions/$SESSION_ID/messages" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{"content": "How do I implement a singleton?"}'

# Second message
curl -X POST "https://gateway.example.com/api/v1/sessions/$SESSION_ID/messages" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{"content": "Now show a thread-safe variant."}'
```

---

### 18.12. Async + webhook

**curl:**

```bash
# Submit an async request (callback_url must be in the client's whitelist)
curl -X POST https://gateway.example.com/api/v1/messages/async \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_your_key" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 8192,
    "messages": [
      {"role": "user", "content": "Write a detailed analysis of the current AI market."}
    ],
    "callback_url": "https://your-api.example.com/api/llm-callback"
  }'

# Response: 202 Accepted
# {
#   "request_id": "req_abc123def456ghi789jkl012",
#   "status": "accepted",
#   "estimated_cost_usd": 0.032100,
#   "estimate_mode": "character_based",
#   "callback_url": "https://your-api.example.com/api/llm-callback",
#   "expires_at": "2026-04-15T10:00:00+00:00"
# }

# Optional polling (instead of waiting for the webhook)
curl https://gateway.example.com/api/v1/messages/req_abc123def456ghi789jkl012 \
  -H "Authorization: Bearer gw_live_your_key"
```

The webhook receives a wrapped envelope (see section 6) with the `X-Gateway-Signature` signature.

Example handler on the client side (Python / Flask):

```python
import hmac
import hashlib
from flask import Flask, request, jsonify

app = Flask(__name__)
SIGNING_SECRET = "your_signing_secret"

@app.route("/api/llm-callback", methods=["POST"])
def llm_callback():
    signature = request.headers.get("X-Webhook-Signature", "")
    timestamp = request.headers.get("X-Webhook-Timestamp", "")
    signed_payload = f"{timestamp}.".encode() + request.data
    expected = "sha256=" + hmac.new(
        SIGNING_SECRET.encode(), signed_payload, hashlib.sha256
    ).hexdigest()

    if not hmac.compare_digest(expected, signature):
        return jsonify({"error": "Invalid signature"}), 401

    payload = request.json
    request_id = payload["request_id"]
    event = payload["event"]

    if event == "message.completed":
        response = payload["anthropic_response"]
        text = response["content"][0]["text"]
        print(f"[{request_id}] Result: {text[:200]}...")
    elif event == "message.failed":
        print(f"[{request_id}] Error: {payload.get('error')}")

    return jsonify({"ok": True}), 200
```
