# LLM Gateway v4 -- Руководство по интеграции

- **Версия протокола:** 4.0
- **Формат:** JSON (Anthropic Messages API)
- **Провайдер:** Claude (Anthropic)
- **Дата актуализации:** 2026-04-21

---

## 1. Quickstart (за 5 минут)

Два способа начать работу: через Anthropic SDK (рекомендуется) или напрямую через HTTP.

### Вариант A: Anthropic Python SDK (рекомендуется)

```bash
pip install anthropic
```

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    messages=[
        {"role": "user", "content": "Объясни теорему Байеса простым языком."}
    ],
)

print(message.content[0].text)
```

### Вариант B: curl

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "messages": [
      {"role": "user", "content": "Объясни теорему Байеса простым языком."}
    ]
  }'
```

Ответ:

```json
{
  "id": "msg_01XFDUDYJgAACzvnptvVoYEL",
  "type": "message",
  "role": "assistant",
  "model": "claude-sonnet-4-6",
  "content": [
    {
      "type": "text",
      "text": "Теорема Байеса позволяет обновить вероятность гипотезы..."
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

Заголовки ответа содержат метаданные gateway (см. раздел 4).

---

## 2. Использование Anthropic SDK с нашим gateway

Gateway принимает запросы в формате Anthropic Messages API, поэтому официальные SDK работают напрямую -- достаточно заменить `base_url`.

### Python

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

# Синхронный запрос
message = client.messages.create(
    model="claude-sonnet",
    max_tokens=2048,
    system="Ты полезный ассистент.",
    messages=[
        {"role": "user", "content": "Что такое REST API?"}
    ],
)

# Streaming
with client.messages.stream(
    model="claude-sonnet",
    max_tokens=2048,
    messages=[{"role": "user", "content": "Напиши короткий рассказ."}],
) as stream:
    for text in stream.text_stream:
        print(text, end="", flush=True)

# Token counting
result = client.messages.count_tokens(
    model="claude-sonnet",
    messages=[{"role": "user", "content": "Привет!"}],
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
                "messages": [{"role": "user", "content": "Привет!"}],
            },
        }
    ]
)
```

### TypeScript

```typescript
import Anthropic from "@anthropic-ai/sdk";

const client = new Anthropic({
  apiKey: "gw_live_ваш_ключ",
  baseURL: "https://gateway.example.com/api/v1",
});

const message = await client.messages.create({
  model: "claude-sonnet",
  max_tokens: 1024,
  messages: [{ role: "user", content: "Что такое TypeScript?" }],
});

console.log(message.content[0].text);
```

### Работающие методы SDK

| Метод SDK | Endpoint gateway |
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

### Ключевые отличия от прямого Anthropic API

**Формат ключа.** Gateway использует ключи формата `gw_live_*` вместо `sk-ant-*`. Ключ передается в заголовке `Authorization: Bearer gw_live_...` точно так же, как и обычный Anthropic-ключ.

**Модели -- только алиасы.** В поле `model` принимаются исключительно алиасы: `claude-opus`, `claude-sonnet`, `claude-haiku`. Передача snapshot-имени (например, `claude-sonnet-4-6`) приведет к ошибке 400. Gateway сам разрешает алиас в актуальный snapshot.

**Дополнительные заголовки ответа.** Каждый ответ содержит заголовки `X-Gateway-*` с метаданными: стоимость, request ID, информация о модели и кэше (см. раздел 4). SDK их игнорирует, но они доступны через объект raw response.

**Эндпоинты, недоступные через SDK.** Следующие возможности gateway не имеют аналогов в Anthropic API и требуют прямых HTTP-запросов:

- `POST /api/v1/messages/async` -- асинхронная обработка с webhook
- `POST /api/v1/messages/batch` -- batch-аккумулятор
- `POST /api/v1/sessions` и все `/sessions/*` -- серверные сессии
- `POST /api/v1/skills` и все `/skills/*` -- навыки (skills)
- `GET /api/v1/clients/me/usage` -- отчет по расходам
- `GET /api/v1/messages/{requestId}` -- получение результата async-запроса

---

## 3. Аутентификация

### Формат ключа

API-ключи gateway имеют формат `gw_live_` + 32 символа (base62):

```
gw_live_a7Bk3mNpQrSt9uVwXyZ1c2D4e5F6g7H8
```

### Передача ключа

Ключ передается в заголовке `Authorization`:

```
Authorization: Bearer gw_live_a7Bk3mNpQrSt9uVwXyZ1c2D4e5F6g7H8
```

Все эндпоинты `/api/v1/*` требуют аутентификации.

### Получение ключа

Ключ создается администратором:

```bash
docker exec llm_gateway php artisan client:create my_service --rate-limit=120
```

При создании выводятся **API Key** и **Signing Secret** (для верификации webhook-подписей). Оба значения отображаются один раз -- сохраните их немедленно.

### Ротация ключей

```bash
docker exec llm_gateway php artisan client:rotate-key <client_id>
```

Ротация API-ключа атомарна: старый ключ сразу перестаёт работать, в консоль выводится новый. Для signing secret предусмотрен grace period:

```bash
docker exec llm_gateway php artisan client:rotate-secret <client_id>
```

После ротации signing secret старое значение продолжает приниматься в течение `webhook.grace_period_seconds` (по умолчанию 86400 секунд = 24 часа).

### Рекомендации по безопасности

- Храните ключ в переменных окружения, не в коде.
- Не передавайте ключ в URL-параметрах.
- Используйте разные ключи для разных окружений (staging, production).
- Регулярно выполняйте ротацию ключей.
- При компрометации ключа -- немедленная ротация с минимальным TTL.

---

## 4. Базовый запрос POST /api/v1/messages

Gateway работает как **pure pass-through** к Anthropic Messages API. Тело запроса -- это **bare Anthropic Message object**, передаваемое без обертки и модификаций. Gateway транслирует его напрямую в Anthropic API, добавляя только служебные заголовки.

### Запрос

```
POST /api/v1/messages
Content-Type: application/json
Authorization: Bearer gw_live_ваш_ключ
```

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "system": "Ты эксперт по Python.",
  "messages": [
    {
      "role": "user",
      "content": "Как работает GIL в Python?"
    }
  ]
}
```

### Ответ (200 OK)

Тело ответа -- стандартный Anthropic Message object:

```json
{
  "id": "msg_01XFDUDYJgAACzvnptvVoYEL",
  "type": "message",
  "role": "assistant",
  "model": "claude-sonnet-4-6",
  "content": [
    {
      "type": "text",
      "text": "GIL (Global Interpreter Lock) -- это механизм CPython..."
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

### Заголовки ответа X-Gateway-*

Каждый ответ содержит метаданные gateway:

| Заголовок | Описание | Пример |
|-----------|----------|--------|
| `X-Gateway-Request-Id` | Уникальный ID запроса в gateway | `req_abc123def456ghi789jkl012` |
| `X-Gateway-Anthropic-Request-Id` | ID запроса в Anthropic API | `req_01A2B3C4D5E6F7G8H9` |
| `X-Gateway-Model-Alias` | Алиас модели, указанный в запросе | `claude-sonnet` |
| `X-Gateway-Model-Snapshot` | Фактический snapshot модели | `claude-sonnet-4-6` |
| `X-Gateway-Cost-USD` | Стоимость запроса в USD | `0.004215` |
| `X-Gateway-Cost-Breakdown` | Детализация стоимости (base64 JSON) | `eyJpbnB1dCI6MC4w...` |
| `X-Gateway-Spend-Remaining-USD` | Остаток бюджета клиента | `95.780000` или `unlimited` |
| `X-Gateway-Service-Tier-Used` | Использованный service tier | `standard` |
| `X-Gateway-Cache-Hit-Tokens` | Количество токенов из кэша | `1500` |

Заголовок `X-Gateway-Cost-Breakdown` содержит base64-кодированный JSON с детализацией:

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

Для streaming-ответов передайте `"stream": true` в теле запроса. Gateway выполняет **pure pass-through** потока SSE (Server-Sent Events) от Anthropic.

### Запрос

```json
{
  "model": "claude-sonnet",
  "max_tokens": 2048,
  "stream": true,
  "messages": [
    {"role": "user", "content": "Напиши стихотворение о программировании."}
  ]
}
```

### Формат ответа

HTTP-ответ имеет Content-Type `text/event-stream`. Поток состоит из SSE-событий:

```
event: message_start
data: {"type":"message_start","message":{"id":"msg_01...","type":"message","role":"assistant","model":"claude-sonnet-4-6","content":[],"stop_reason":null,"usage":{"input_tokens":15,"output_tokens":0}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"В мире"}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" нулей и единиц"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":85}}

event: message_stop
data: {"type":"message_stop"}
```

### Типы SSE-событий

| Событие | Описание |
|---------|----------|
| `message_start` | Начало сообщения, содержит metadata и usage входных токенов |
| `content_block_start` | Начало блока контента (text, tool_use, thinking) |
| `content_block_delta` | Инкрементальный фрагмент контента |
| `content_block_stop` | Завершение блока контента |
| `message_delta` | Финальные metadata (stop_reason, output usage) |
| `message_stop` | Конец потока |
| `ping` | Keep-alive |
| `error` | Ошибка в процессе генерации |

### Ошибка после HTTP 200

При streaming HTTP-статус 200 отправляется до начала генерации. Если ошибка возникает в процессе, она приходит как SSE-событие `error`:

```
event: error
data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}
```

Клиент должен обрабатывать такие ошибки. SDK делает это автоматически, выбрасывая соответствующее исключение.

### Заголовки streaming-ответа

Заголовки `X-Gateway-Request-Id`, `X-Gateway-Model-Alias`, `X-Gateway-Model-Snapshot` доступны в начальном HTTP-ответе. Стоимость (`X-Gateway-Cost-USD`) для streaming недоступна в заголовках, так как рассчитывается после завершения потока -- используйте `usage` из события `message_delta`.

---

## 6. Async + webhook POST /api/v1/messages/async

Асинхронный режим: gateway принимает запрос, помещает его в очередь и доставляет результат на webhook URL клиента.

### Запрос

```
POST /api/v1/messages/async
Content-Type: application/json
Authorization: Bearer gw_live_ваш_ключ
```

```json
{
  "model": "claude-opus",
  "max_tokens": 4096,
  "messages": [
    {"role": "user", "content": "Проведи детальный анализ архитектуры микросервисов."}
  ],
  "callback_url": "https://your-api.example.com/api/llm-callback"
}
```

**Важно:** значение `callback_url` должно быть **предварительно зарегистрировано** в whitelist клиента (таблица `client_callback_urls`). Запрос с незарегистрированным URL отклоняется с ошибкой 400 `invalid_request_error: callback_url is not whitelisted for this client`.

### Ответ (202 Accepted)

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

Заголовок: `X-Gateway-Request-Id: req_abc123def456ghi789jkl012`

TTL async-записи: `async.pending_ttl_days` (по умолчанию 3 дня).

### Получение результата по ID

Вместо ожидания webhook можно опросить статус:

```
GET /api/v1/messages/{requestId}
Authorization: Bearer gw_live_ваш_ключ
```

Ответ (пример для завершённого запроса):

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

Возможные значения `status`: `accepted`, `in_progress`, `completed`, `completed_disconnected`, `failed_client_error`, `failed_server_error`, `failed_callback_delivery`, `failed_validation`, `failed_auth`. Если нужно опустить полезную нагрузку -- передайте `?include_response=false`.

### Доставка webhook

Gateway доставляет результат на `callback_url` в виде **wrapped envelope** -- оригинальный ответ Anthropic обернут в конверт с метаданными gateway:

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
        "text": "Архитектура микросервисов..."
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

Возможные значения `event`: `message.completed`, `message.failed`. При failed -- поле `error: {type, message}` вместо `anthropic_response`.

### HMAC-подпись webhook

Каждый webhook подписан HMAC-SHA256 с использованием signing secret клиента. Подписываемая строка -- `{timestamp}.{body}` (timestamp из заголовка, тело -- сырые байты запроса). Заголовки:

| Заголовок | Описание |
|-----------|----------|
| `X-Webhook-Signature` | `sha256={hex}` |
| `X-Webhook-Timestamp` | Unix timestamp (секунды), участвует в подписи |
| `X-Webhook-Request-Id` | Gateway request id (для идемпотентности) |
| `X-Webhook-Event` | `message.completed` или `message.failed` |

### Валидация подписи (Python)

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

### Политика повторных попыток

| Параметр | Значение |
|----------|----------|
| Максимум попыток | 10 |
| Стратегия | Экспоненциальный backoff |
| Начальная задержка | 10 секунд |
| Максимальная задержка | 3600 секунд (1 час) |
| Таймаут каждого запроса | 30 секунд |
| Grace period URL | 86400 секунд (24 часа) |

Если все попытки исчерпаны, запрос помечается как `failed`. Результат по-прежнему доступен через `GET /api/v1/messages/{requestId}`.

---

## 7. Batch API

Batch API позволяет отправить до 100 000 запросов одним вызовом с 50% скидкой на стоимость обработки. Результаты доступны в течение 24 часов.

### Два режима

**Immediate batch** (`POST /api/v1/batches`) -- отправляет batch немедленно в Anthropic API. Полная совместимость с SDK (`client.messages.batches.create()`).

**Batch-аккумулятор** (`POST /api/v1/messages/batch`) -- запросы накапливаются на стороне gateway и отправляются, когда сработает один из триггеров:

| Триггер | Порог |
|---------|-------|
| Количество запросов | 100 |
| Суммарный размер | 50 МБ |
| Время накопления | 300 секунд (5 минут) |

### Создание immediate batch

```bash
curl -X POST https://gateway.example.com/api/v1/batches \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "requests": [
      {
        "custom_id": "task-001",
        "params": {
          "model": "claude-sonnet",
          "max_tokens": 1024,
          "messages": [{"role": "user", "content": "Столица Франции?"}]
        }
      },
      {
        "custom_id": "task-002",
        "params": {
          "model": "claude-sonnet",
          "max_tokens": 1024,
          "messages": [{"role": "user", "content": "Столица Японии?"}]
        }
      }
    ]
  }'
```

Ответ:

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

### Проверка статуса

```bash
curl https://gateway.example.com/api/v1/batches/bat_abc123def456ghi789jkl012 \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

### Получение результатов

```bash
curl https://gateway.example.com/api/v1/batches/bat_abc123def456ghi789jkl012/results \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

Формат результатов -- JSONL (каждая строка -- JSON-объект):

```json
{"custom_id":"task-001","result":{"type":"succeeded","message":{"id":"msg_01...","type":"message","role":"assistant","content":[{"type":"text","text":"Столица Франции -- Париж."}],"model":"claude-sonnet-4-6","stop_reason":"end_turn","usage":{"input_tokens":12,"output_tokens":15}}}}
{"custom_id":"task-002","result":{"type":"succeeded","message":{"id":"msg_02...","type":"message","role":"assistant","content":[{"type":"text","text":"Столица Японии -- Токио."}],"model":"claude-sonnet-4-6","stop_reason":"end_turn","usage":{"input_tokens":12,"output_tokens":14}}}}
```

### Управление batch

```bash
# Отмена
curl -X POST https://gateway.example.com/api/v1/batches/bat_.../cancel \
  -H "Authorization: Bearer gw_live_ваш_ключ"

# Удаление (после завершения)
curl -X DELETE https://gateway.example.com/api/v1/batches/bat_... \
  -H "Authorization: Bearer gw_live_ваш_ключ"

# Список всех batch
curl https://gateway.example.com/api/v1/batches \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

### Стоимость batch

Batch-запросы стоят 50% от обычной цены:

| Модель | Input (обычный) | Input (batch) | Output (обычный) | Output (batch) |
|--------|:---------------:|:-------------:|:----------------:|:--------------:|
| claude-opus | $5.00 | $2.50 | $25.00 | $12.50 |
| claude-sonnet | $3.00 | $1.50 | $15.00 | $7.50 |
| claude-haiku | $1.00 | $0.50 | $5.00 | $2.50 |

Цены за 1M токенов.

### Лимиты

- Максимум запросов в batch: 100 000
- Максимальный max_output_tokens в batch: 300 000 (opus, sonnet) / 64 000 (haiku)
- Время жизни результатов: 24 часа

---

## 8. Sessions (multi-turn)

Sessions -- серверная реализация multi-turn диалогов. Gateway хранит историю сообщений на сервере, позволяя клиенту не передавать всю историю в каждом запросе.

### Когда использовать

- Чат-боты и диалоговые интерфейсы
- Длительные контексты, которые не помещаются в одно сообщение клиента
- Сценарии с compaction (сжатие длинного контекста)

### Когда НЕ использовать

- Одиночные запросы без продолжения
- Batch-обработка
- Случаи, когда клиент хочет полностью контролировать историю

### Создание сессии

```bash
curl -X POST https://gateway.example.com/api/v1/sessions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-sonnet",
    "system": "Ты полезный ассистент-программист.",
    "max_tokens": 4096
  }'
```

Ответ:

```json
{
  "session_id": "ses_abc123def456ghi789jkl012",
  "model": "claude-sonnet",
  "created_at": "2026-04-12T10:00:00Z",
  "expires_at": "2026-05-12T10:00:00Z",
  "message_count": 0
}
```

### Отправка сообщения в сессию

```bash
curl -X POST https://gateway.example.com/api/v1/sessions/ses_abc123.../messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "content": "Как реализовать паттерн Observer на Python?"
  }'
```

Gateway автоматически подставляет историю сессии в контекст. Ответ -- стандартный Anthropic Message object.

### Получение истории

```bash
curl https://gateway.example.com/api/v1/sessions/ses_abc123.../messages \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

### Compaction

При приближении к лимиту контекстного окна gateway автоматически применяет compaction -- сжатие ранних сообщений в краткое саммари. Клиент получает заголовок `X-Gateway-Warning: auto_resume_limit_reached`, когда compaction активирован. Подробнее -- раздел 16.

### Удаление сессии

```bash
curl -X DELETE https://gateway.example.com/api/v1/sessions/ses_abc123... \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

Время жизни сессии по умолчанию: 30 дней.

---

## 9. Server-side tools

Gateway поддерживает серверные инструменты Claude, которые выполняются на стороне Anthropic. Для активации инструмента добавьте его в массив `tools` запроса.

### web_search

Поиск в интернете в реальном времени.

**Стоимость:** $10 за 1000 вызовов.

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
    {"role": "user", "content": "Какие новости в мире AI за последнюю неделю?"}
  ]
}
```

### web_fetch

Загрузка содержимого веб-страницы по URL.

**Стоимость:** бесплатно.

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
    {"role": "user", "content": "Загрузи и суммаризируй содержимое https://example.com/article"}
  ]
}
```

### code_execution

Выполнение кода в изолированной песочнице.

**Стоимость:** $0.05/час (1550 бесплатных часов в месяц).

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
    {"role": "user", "content": "Вычисли первые 20 чисел Фибоначчи и построй график."}
  ]
}
```

### text_editor

Инструмент для редактирования текстовых файлов (создание, чтение, замена).

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
    {"role": "user", "content": "Создай файл config.yaml с настройками для веб-сервера."}
  ]
}
```

### bash

Выполнение bash-команд в песочнице.

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
    {"role": "user", "content": "Покажи список файлов в текущей директории."}
  ]
}
```

### computer_use (beta)

Управление компьютером через скриншоты и действия мыши/клавиатуры.

**Требует beta-заголовок.** Gateway автоматически добавляет необходимый beta-заголовок.

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
    {"role": "user", "content": "Открой браузер и перейди на example.com"}
  ]
}
```

### tool_search

Поиск по доступным инструментам в каталоге.

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
    {"role": "user", "content": "Найди инструмент для работы с таблицами."}
  ]
}
```

### memory

Инструмент долговременной памяти для сохранения и извлечения заметок между сессиями.

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
    {"role": "user", "content": "Запомни, что мой проект использует Python 3.12 и FastAPI."}
  ]
}
```

### Programmatic tool calling (пользовательские инструменты)

Помимо серверных инструментов, Claude поддерживает пользовательские tools с описанием JSON Schema. Claude решает, когда вызвать инструмент, и возвращает `tool_use` content block. Клиент выполняет инструмент и отправляет `tool_result` обратно.

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "tools": [
    {
      "name": "get_weather",
      "description": "Получает текущую погоду для указанного города.",
      "input_schema": {
        "type": "object",
        "properties": {
          "city": {"type": "string", "description": "Название города"}
        },
        "required": ["city"]
      }
    }
  ],
  "messages": [
    {"role": "user", "content": "Какая погода в Москве?"}
  ]
}
```

Ответ с `tool_use`:

```json
{
  "content": [
    {
      "type": "tool_use",
      "id": "toolu_01A2B3C4D5E6",
      "name": "get_weather",
      "input": {"city": "Москва"}
    }
  ],
  "stop_reason": "tool_use"
}
```

Клиент вызывает свой API погоды и возвращает результат:

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "tools": [
    {
      "name": "get_weather",
      "description": "Получает текущую погоду для указанного города.",
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
    {"role": "user", "content": "Какая погода в Москве?"},
    {
      "role": "assistant",
      "content": [
        {
          "type": "tool_use",
          "id": "toolu_01A2B3C4D5E6",
          "name": "get_weather",
          "input": {"city": "Москва"}
        }
      ]
    },
    {
      "role": "user",
      "content": [
        {
          "type": "tool_result",
          "tool_use_id": "toolu_01A2B3C4D5E6",
          "content": "Москва: +15C, облачно, ветер 5 м/с"
        }
      ]
    }
  ]
}
```

### Citations

Claude может возвращать citations -- ссылки на исходные фрагменты текста, которые использовались для генерации ответа. Citations автоматически включаются при наличии документов в контексте.

### Search result blocks (RAG)

Для реализации RAG-паттерна передавайте результаты поиска как `search_result` content blocks. Claude будет использовать их как контекст и может ссылаться на них через citations.

### Structured outputs (output_config)

Для получения структурированного JSON-ответа используйте параметр `output_config` с JSON Schema:

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
    {"role": "user", "content": "Проанализируй тональность: 'Отличный продукт, рекомендую!'"}
  ]
}
```

### MCP connector (beta)

См. §13.3 «MCP connector».

### Inference geo

По умолчанию все запросы обрабатываются в регионе US. Это настраивается на уровне шлюза.

---

## 10. Files API

Files API позволяет загружать файлы на сервер и ссылаться на них в запросах по ID, избегая повторной передачи больших файлов.

### Загрузка файла

```bash
curl -X POST https://gateway.example.com/api/v1/files \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -F "file=@document.pdf"
```

Ответ:

```json
{
  "id": "file_abc123def456ghi789jkl012",
  "filename": "document.pdf",
  "mime_type": "application/pdf",
  "size_bytes": 2458901,
  "created_at": "2026-04-12T10:00:00Z"
}
```

### Использование файла в запросе

Ссылайтесь на загруженный файл через content block типа `file`:

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
          "text": "Суммаризируй этот документ."
        }
      ]
    }
  ]
}
```

### Список файлов

```bash
curl https://gateway.example.com/api/v1/files \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

### Удаление файла

```bash
curl -X DELETE https://gateway.example.com/api/v1/files/file_abc123... \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

### Лимиты

- Максимальный размер файла: 500 МБ
- Файлы без обращений автоматически помечаются через 90 дней
- Безвозвратное удаление: 14 дней после пометки

---

## 11. Token counting и estimation

### Подсчет токенов

Endpoint `POST /api/v1/messages/count_tokens` позволяет узнать количество входных токенов до отправки запроса.

```bash
curl -X POST https://gateway.example.com/api/v1/messages/count_tokens \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-sonnet",
    "messages": [
      {"role": "user", "content": "Привет, как дела?"}
    ]
  }'
```

Ответ:

```json
{
  "input_tokens": 12
}
```

### Заголовки ответа

| Заголовок | Описание |
|-----------|----------|
| `X-Gateway-Request-Id` | ID запроса |
| `X-Gateway-Estimated-Cost-USD` | Оценочная стоимость полного запроса |

Оценка стоимости рассчитывается как: `input_tokens * input_price + (input_tokens * 0.5) * output_price`. Множитель 0.5 -- эвристический коэффициент для оценки выходных токенов.

### Оценка бюджета

Для планирования расходов:

1. Вызовите `count_tokens` с репрезентативным запросом.
2. Используйте `X-Gateway-Estimated-Cost-USD` для оценки стоимости одного запроса.
3. Умножьте на ожидаемое количество запросов.

Для проверки фактических расходов используйте:

```bash
curl https://gateway.example.com/api/v1/clients/me/usage \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

---

## 12. Prompt caching

Prompt caching позволяет кэшировать часть контекста между запросами, экономя до 90% стоимости входных токенов и увеличивая пропускную способность до 5 раз.

### Как работает

Anthropic кэширует контент, помеченный точками кэширования (`cache_control`). При повторном запросе с тем же префиксом кэшированные токены не перечитываются, а берутся из кэша.

### Минимальные размеры кэшируемого контента

| Модель | Минимум токенов для кэширования |
|--------|:-------------------------------:|
| claude-opus | 1024 |
| claude-sonnet | 1024 |
| claude-haiku | 2048 |

Если контент короче минимального размера, кэширование не применяется, но ошибки не возникает.

### TTL кэша

| TTL | Цена записи (множитель от input) | Цена чтения (множитель от input) |
|-----|:---------------------------------:|:---------------------------------:|
| 5 минут (по умолчанию) | 1.25x | ~0.1x |
| 1 час | ~2x | ~0.1x |

### Стратегия размещения cache_control

Кэшируйте то, что повторяется между запросами:

1. **System prompt** -- самый частый кандидат
2. **Статические инструменты (tools)** -- не меняются между запросами
3. **Документы и контекст** -- общий контекст для серии запросов
4. **Начало истории** -- ранние сообщения в multi-turn диалоге

Размещайте `cache_control` в конце кэшируемого блока.

### Правило 20 блоков

Anthropic обрабатывает `cache_control` breakpoints в последних 20 блоках, помеченных `cache_control`. Breakpoints за пределами 20 игнорируются (считая от конца).

### Автоматическое кэширование

Gateway по умолчанию включает автоматическое кэширование верхнего уровня (`auto_top_level`). Это означает, что system prompt и tools автоматически получают `cache_control` breakpoint, если их размер превышает минимальный порог.

### Ручное управление cache_control

```json
{
  "model": "claude-sonnet",
  "max_tokens": 1024,
  "system": [
    {
      "type": "text",
      "text": "Ты эксперт по анализу данных. Вот база знаний: ...(длинный текст)...",
      "cache_control": {"type": "ephemeral"}
    }
  ],
  "messages": [
    {"role": "user", "content": "Проанализируй тренд за Q1."}
  ]
}
```

TTL 1 час (для batch-запросов автоматически):

```json
{
  "cache_control": {"type": "ephemeral", "ttl": "1h"}
}
```

### Anti-patterns

- Не ставьте `cache_control` на каждый блок -- это не ускоряет, а может замедлить обработку.
- Не кэшируйте контент, который меняется каждый запрос -- вы платите за запись, но никогда не читаете из кэша.
- Не кэшируйте контент меньше минимального размера -- breakpoint будет проигнорирован.
- Помните о лимите 20 breakpoints -- если вы используете больше, самые ранние игнорируются.

### Cache-aware ITPM

При активном кэшировании фактическое потребление ITPM (Input Tokens Per Minute) на стороне Anthropic снижается, так как кэшированные токены обрабатываются быстрее. Это позволяет эффективнее использовать rate limits.

---

## 13. Расширенные возможности

Эта секция описывает дополнительные возможности gateway, которые могут требовать предварительной активации через `allowed_features`. Каждая подсекция содержит назначение, формат запроса/ответа, требования к `allowed_features`, коды ошибок и примеры `curl`.

### 13.1 Skills API

**Назначение.** Skills — подключаемые «знания» Claude, доступные внутри инструмента `code_execution`. Сейчас поддерживаются только prebuilt-скиллы (работа с офисными форматами). Custom-скиллы пока не принимаются и возвращают `501 custom_skills_not_yet_supported`.

**Эндпоинты.**

- `POST /api/v1/skills` — создать prebuilt-скилл для клиента.
- `GET /api/v1/skills` — список активных скиллов клиента.
- `GET /api/v1/skills/{skill_id}` — карточка одного скилла.
- `DELETE /api/v1/skills/{skill_id}` — soft-delete скилла.

Идентификатор имеет формат `skl_` + 24 символа `[A-Za-z0-9]` и задан в роутинге (`routes/api.php`, regex `skl_[A-Za-z0-9]{24}`). Заголовки аутентификации — см. §3.

#### Формат запроса `POST /api/v1/skills`

```json
{
  "type": "prebuilt",
  "name": "xlsx"
}
```

Поля:

- `type` (string, обязательное): `"prebuilt"` — единственный поддерживаемый вариант; `"custom"` вернёт `501`.
- `name` (string, обязательное для `prebuilt`): одно из `xlsx`, `docx`, `pptx`, `pdf`. Полный список — конфиг `claude.skills.prebuilt`.

#### Формат ответа `POST /api/v1/skills` (201 Created)

```json
{
  "skill_id": "skl_Ab12Cd34Ef56Gh78Ij90Kl12",
  "name": "xlsx",
  "type": "prebuilt",
  "is_prebuilt": true,
  "created_at": "2026-04-21T10:15:00+00:00"
}
```

Поле `version` здесь **не возвращается** — оно добавляется только в ответах `GET /skills/{id}` и `GET /skills`.

#### Формат ответа `GET /api/v1/skills/{skill_id}` (200 OK)

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

#### Формат ответа `GET /api/v1/skills` (200 OK)

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

Удаление — soft: запись помечается `is_deleted = true`, тело ответа пустое. Повторное создание скилла с тем же `name` после удаления порождает **новый** `skill_id`.

#### Использование skills в `/api/v1/messages`

Чтобы задействовать скилл в обычном запросе, передайте массив `skill_id` в поле `skills` и одновременно добавьте в `tools` инструмент с `type`, начинающимся на `code_execution` (валидатор использует `str_starts_with`-style проверку — любой `tool.type`, начинающийся с `code_execution`, проходит prefix-проверку для skills). Без такого инструмента gateway вернёт `400 skills_require_code_execution`.

В примерах используйте актуальное значение `code_execution_20260120` (константа `ToolTypeCatalog::CODE_EXECUTION`). Это единственный идентификатор, который одновременно проходит feature-check `code_execution` через exact-match в `ToolTypeCatalog::FEATURE_MAP` — legacy-значения (например, `code_execution_20250522`) пройдут prefix-проверку для skills, но провалят feature-check.

**Требования к `allowed_features`.** Нужна фича `skills: true`. **Фича `skills` не входит в список `ClientEnableFeature::KNOWN_FEATURES` — CLI `php artisan client:enable-feature <id> skills` отклонит команду как «Unknown feature». Активация возможна только прямым обновлением JSON-колонки `allowed_features` у клиента (см. §13.6).**

**Beta-header.** При непустом `payload.skills` gateway автоматически добавляет заголовок `anthropic-beta: skills-2025-10-02` (конфиг `claude.beta_headers.skills`; логика — `PayloadBuilder::detectBetaFeatures`).

#### Коды ошибок Skills API

| HTTP | `error.type` | Условие |
|------|-------------|---------|
| 400 | `invalid_skill_type` | `POST /skills`: `type` не равен `"prebuilt"` или `"custom"`. |
| 400 | `skill_creation_failed` | `POST /skills`: неизвестный prebuilt-`name` (сообщение `Unknown prebuilt skill: '<name>'`). |
| 400 | `skills_not_enabled` | Payload в `/messages`: поле `skills` задано, но у клиента нет фичи `skills` (проверка в `MessageRequestValidator`, это уже не Skills API). |
| 400 | `skills_require_code_execution` | Payload в `/messages`: `skills` задано, но ни один `tools[].type` не начинается на `code_execution`. |
| 403 | `skill_creation_failed` | `POST /skills` без фичи `skills`. Сообщение — `Skills feature is not enabled for this client`. Коллизия с `400` — различать по HTTP-коду. |
| 403 | `skills_list_failed` | `GET /skills` без фичи `skills`. |
| 403 | `skill_delete_failed` | `DELETE /skills/{id}` без фичи `skills`. |
| 404 | `skill_not_found` | `GET /skills/{id}` / `DELETE /skills/{id}`: id неизвестен, soft-deleted либо принадлежит другому клиенту. |
| 501 | `custom_skills_not_yet_supported` | `POST /skills`: `type: "custom"`. |

**Важное примечание про gating.** Ошибки активации в Skills API устроены иначе, чем у server-tools. `SkillsOrchestrator::assertSkillsEnabled` бросает `RuntimeException` с HTTP-кодом `403`, но контроллер сам ловит исключение и возвращает эндпоинт-специфичный `error.type`: `POST /skills` → `skill_creation_failed`, `GET /skills` → `skills_list_failed`, `DELETE /skills/{id}` → `skill_delete_failed`. Поле `error.type` в теле ответов Skills API всегда берётся из таблицы выше — «общая» валидационная ошибка `skills_not_enabled` возникает только в `/messages`. Метод `GET /skills/{id}` **не вызывает** `assertSkillsEnabled`: для клиента без фичи результатом будет `404 skill_not_found` (скилл либо не создан, либо принадлежит другому клиенту), а не `403`.

#### Ограничения и взаимные несовместимости

- Custom-скиллы — всегда `501 custom_skills_not_yet_supported`, загрузка артефактов не поддерживается.
- `skills` в payload без инструмента `code_execution*` — `400 skills_require_code_execution`.
- Soft-delete необратим на уровне API: новый `POST /skills` с тем же `name` создаёт запись с другим `skill_id` и `version: 1`.

#### Примеры `curl`

Создать prebuilt-скилл для Excel.

```bash
curl -X POST https://gateway.example.com/api/v1/skills \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{"type":"prebuilt","name":"xlsx"}'
```

Использовать скилл в `/api/v1/messages` вместе с `code_execution_20260120`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "skills": ["skl_Ab12Cd34Ef56Gh78Ij90Kl12"],
    "tools": [
      {"type": "code_execution_20260120", "name": "code_execution"}
    ],
    "messages": [
      {"role": "user", "content": "Открой приложенный .xlsx и посчитай сумму колонки B."}
    ]
  }'
```

Попытка создать скилл для клиента без фичи `skills` — ответ `403`.

```bash
curl -X POST https://gateway.example.com/api/v1/skills \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
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

**Назначение.** Ускоренный инференс Claude Opus: приоритетная обработка запроса на стороне Anthropic ценой повышенного тарифа. Применимо там, где latency критичнее стоимости (интерактивный чат, интерактивные подсказки). Для `count_tokens` режим не имеет смысла — валидатор специальной ошибки не возвращает, но запрос «посчитать токены быстрее» бессмыслен; не используйте `speed: "fast"` в таких сценариях.

**Параметр payload.** В теле запроса `POST /api/v1/messages` (и в session/async контекстах, использующих тот же валидатор) — поле `speed`. Допустимые значения из enum `App\Components\Validation\Enums\Speed`:

- `"standard"` — значение по умолчанию;
- `"fast"` — fast mode.

Любое другое значение → `400 speed_invalid`.

**Поддержка по моделям.** Fast mode доступен только на `claude-opus`. Источник — колонка `supports_fast_mode` в `config/llm.php → claude.model_capabilities`:

| Модель | `supports_fast_mode` |
|--------|----------------------|
| `claude-opus` | `true` |
| `claude-sonnet` | `false` |
| `claude-haiku` | `false` |

Полные характеристики моделей — см. §14. Выбор модели.

**Требование `allowed_features`.** Нужна фича `fast_mode: true`. **CLI `php artisan client:enable-feature <id> fast_mode` не поддерживает эту фичу — имя `fast_mode` отсутствует в `ClientEnableFeature::KNOWN_FEATURES`. Активация выполняется прямым обновлением JSON-колонки `allowed_features` у клиента. Подробности — §13.6.**

**Несовместимости.**

- `speed: "fast"` + `service_tier: "auto"` → `400 fast_mode_priority_incompatible` (два ускорителя одновременно не разрешены).
- `speed: "fast"` внутри Batch API (payload пункта `/api/v1/batches`) → `400 fast_mode_batch_incompatible`.
- `speed: "fast"` на `claude-sonnet` или `claude-haiku` → `400 fast_mode_model_unsupported`.
- `count_tokens`: режим семантически не применим; валидатор его не отклоняет специальной ошибкой, но ожидаемого эффекта не даст.

**Ценообразование.** При `speed: "fast"` стоимость `input` и `output` токенов умножается на множитель `×6.0` (`config/llm.php → claude.pricing.fast_multiplier`). Кэш-операции (`cache_write_5m`, `cache_write_1h`, `cache_read`) считаются по обычному тарифу — множитель к ним **не применяется**. Базовые ставки за 1M токенов — см. §14. Выбор модели → «Стоимость за 1M токенов».

**Beta-header.** При `payload.speed === "fast"` gateway автоматически добавляет `anthropic-beta: fast-mode-2026-02-01` (`config/llm.php → claude.beta_headers.fast_mode`; логика — `PayloadBuilder::detectBetaFeatures`).

**Коды ошибок.** Порядок строк повторяет порядок проверок валидатора — первая сработавшая ошибка short-circuit'ит остальные.

| HTTP | `error.type` | Триггер |
|------|-------------|---------|
| 400 | `speed_invalid` | `speed` не входит в enum (`standard` \| `fast`). |
| 400 | `fast_mode_not_enabled` | `speed: "fast"` и у клиента нет фичи `fast_mode`. |
| 400 | `fast_mode_model_unsupported` | `speed: "fast"` на модели без `supports_fast_mode` (`claude-sonnet`, `claude-haiku`). |
| 400 | `fast_mode_batch_incompatible` | `speed: "fast"` в контексте Batch API. |
| 400 | `fast_mode_priority_incompatible` | `speed: "fast"` + `service_tier: "auto"`. |

#### Примеры `curl`

Успешный запрос с fast mode на `claude-opus`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 256,
    "speed": "fast",
    "messages": [
      {"role": "user", "content": "Дай короткий ответ: что такое CAP-теорема?"}
    ]
  }'
```

Тот же запрос на `claude-sonnet` отклоняется с `400 fast_mode_model_unsupported`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 256,
    "speed": "fast",
    "messages": [
      {"role": "user", "content": "Тест"}
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

**Назначение.** Подключение внешних серверов Model Context Protocol (MCP) прямо из запроса к `/api/v1/messages`. Клиенту не нужно хостить инструменты у себя — Anthropic дотягивается до указанного MCP-сервера (обычно SSE по HTTPS) и вызывает его инструменты от имени модели. Типовые сценарии: корпоративный поиск по документации, CRM/ERP lookup, сторонние SaaS-интеграции через MCP.

**Параметр payload.** Поле `mcp_servers` — массив объектов, описывающих внешние MCP-серверы. Также допустимо в session/async-запросах, использующих тот же валидатор.

**Структура элемента.** Пример:

```json
{
  "type": "url",
  "url": "https://mcp.example.com/sse",
  "name": "docs_search",
  "authorization_token": "Bearer <token>",
  "tool_configuration": {}
}
```

| Поле | Обязательное | Тип | Описание |
|------|-------------|-----|----------|
| `type` | да | string | Сейчас наблюдаемое значение — `"url"` (подтверждено в `tests/Unit/Claude/MCPConnectorPayloadTest.php`). |
| `url` | да | string | HTTPS-адрес MCP-сервера (как правило, SSE endpoint). |
| `name` | да | string | Короткий алиас сервера. Anthropic использует его в именах `tool_use`-блоков как `<name>__<tool>`. |
| `authorization_token` | нет | string | Передаётся в заголовке `Authorization` при обращении Anthropic к MCP-серверу. **Маскируется в логах шлюза** (см. «Безопасность»). |
| `tool_configuration` | нет | object | Прокидывается в Anthropic без изменений; шлюз внутреннюю структуру не валидирует. |

Более подробная схема элемента — в документации Anthropic для беты `mcp-client-2025-11-20`.

**Связка с `tools`.** Шлюз **не требует** наличия `mcp_toolset`-маркера в `tools`. Согласно контракту Anthropic допустим паттерн, при котором клиент дополнительно указывает `{"type": "mcp_toolset", "server_name": "<name>"}` в `tools` — если он присутствует, шлюз пробрасывает его как есть, без валидации.

**Контракт ответа.** Для каждого блока `tool_use`, чей `name` имеет форму `<server_name>__<tool>`, шлюз добавляет в блок поле `mcp_server_name: "<server_name>"` — это позволяет клиенту быстро понять, какой MCP-сервер отработал. Для `tool_use`-блоков без `__` в имени это поле не добавляется. Поведение верифицировано в `MCPConnectorPayloadTest::response_parser_tags_mcp_tool_use_blocks`.

**Требование `allowed_features`.** Нужна фича `mcp_connector: true`. **CLI `php artisan client:enable-feature <id> mcp_connector` не поддерживает эту фичу — имя отсутствует в `ClientEnableFeature::KNOWN_FEATURES`. Активация — прямым обновлением JSON-колонки `allowed_features` у клиента. Подробности — §13.6.**

**Beta-header.** При непустом `mcp_servers` шлюз автоматически добавляет `anthropic-beta: mcp-client-2025-11-20` (`config/llm.php → claude.beta_headers.mcp_client`; логика — `PayloadBuilder::detectBetaFeatures` через `hasMcpServers`).

**Несовместимости.** В контексте Batch API (элемент `/api/v1/batches`) MCP не поддерживается контрактом Anthropic. Валидатор шлюза не блокирует это специальной ошибкой, но использовать `mcp_servers` внутри batch не следует — поведение upstream не гарантировано.

**Безопасность.**

- `authorization_token` **маскируется в логах шлюза** как `[REDACTED]` через `PayloadMasker::mask`. На upstream в Anthropic токен уходит в исходном виде — иначе авторизация на MCP-сервере не пройдёт; шлюз не вырезает его из запроса.
- При использовании MCP внутри sessions шлюз хранит `authorization_token` в БД зашифрованным. Подробности session-интеграции выходят за рамки этой секции.

**Коды ошибок.**

| HTTP | `error.type` | Триггер |
|------|-------------|---------|
| 400 | `mcp_connector_not_enabled` | `mcp_servers` непустой, фича `mcp_connector` выключена. |
| 400 | `invalid_request_error` | Нарушение JSON-схемы элемента `mcp_servers[]` (`type`, `url`, `name`) — сообщение формируется валидатором схемы. |

#### Примеры `curl`

Успешный запрос с подключённым MCP-сервером.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
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
      {"role": "user", "content": "Найди в нашей документации, как настраивается retry для webhook."}
    ]
  }'
```

Тот же запрос для клиента без фичи `mcp_connector` отклоняется.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 256,
    "mcp_servers": [
      {"type": "url", "url": "https://mcp.example.com/sse", "name": "docs_search"}
    ],
    "messages": [
      {"role": "user", "content": "Тест"}
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

**Назначение.** Выбор priority-пула пропускной способности Anthropic, когда он доступен. Типовой сценарий — критичные продакшен-потоки, в которых задержка постановки запроса в очередь важнее стоимости.

**Параметр payload.** Поле `service_tier` в `POST /api/v1/messages`. Допустимые значения — enum `App\Components\Validation\Enums\ServiceTier`:

- `"standard_only"` — значение по умолчанию (`config/llm.php → claude.service_tier.default`);
- `"auto"` — попросить priority с прозрачным откатом на standard, если priority-пул недоступен.

**Важно.** Любое другое значение — включая строку `"priority"` — отклоняется валидатором как `400 service_tier_invalid`. Строка `"priority"` встречается только как значение response-заголовка (см. ниже) и **не является валидным client-side вводом**.

**Требование `allowed_features`.** Для `standard_only` активация не нужна. Для `"auto"` — требуется фича `priority_tier: true`. В отличие от `skills` / `fast_mode` / `mcp_connector`, фича `priority_tier` входит в `ClientEnableFeature::KNOWN_FEATURES`, поэтому её можно включить стандартным CLI:

```bash
php artisan client:enable-feature <client_id> priority_tier
```

Подробности — §13.6.

**Response-заголовок `X-Gateway-Service-Tier-Used`.** Выставляется шлюзом в sync-ответах (`SyncResponder`) по значению, которое вернул Anthropic. Возможные состояния:

| Значение | Смысл |
|----------|-------|
| `standard` | Anthropic применил standard-тариф (в т. ч. откат при `auto`). |
| `priority` | Anthropic применил priority. |
| *(отсутствует)* | В ответе апстрима нет поля `service_tier` (например, в части streaming-чанков). |

**Ценообразование.** Если `X-Gateway-Service-Tier-Used === "priority"`, к стоимости `input`- и `output`-токенов применяется множитель `config/llm.php → claude.service_tier.priority_multiplier` (сейчас `1.0`, т. е. без наценки; значение может измениться в будущем). К cache-токенам множитель **не применяется**. Базовые ставки за 1M токенов — см. §14. Выбор модели → «Стоимость за 1M токенов».

**Несовместимости.**

- `service_tier: "auto"` + `speed: "fast"` → `400 fast_mode_priority_incompatible` (см. §13.2).
- Batch-контекст: шлюз **не запрещает** `service_tier: "auto"` внутри элемента batch, но фактическая применимость priority в Batch API определяется контрактом Anthropic. На практике на priority в batch-пайплайнах полагаться не стоит — см. документацию Anthropic Batch API.

**Beta-header.** Для этой фичи beta-header не требуется и шлюзом не добавляется — `service_tier` является first-class параметром Anthropic API.

**Коды ошибок.**

| HTTP | `error.type` | Триггер |
|------|-------------|---------|
| 400 | `service_tier_invalid` | Значение `service_tier` не входит в enum (`standard_only`, `auto`); сюда попадает и строка `"priority"`. |
| 400 | `priority_tier_not_enabled` | `service_tier: "auto"` при выключенной фиче `priority_tier`. |
| 400 | `fast_mode_priority_incompatible` | `service_tier: "auto"` + `speed: "fast"` (см. §13.2). |

#### Примеры `curl`

Успешный запрос с priority на `claude-opus`. В ответе ожидается заголовок `X-Gateway-Service-Tier-Used: priority` (или `standard` при откате).

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -i \
  -d '{
    "model": "claude-opus",
    "max_tokens": 512,
    "service_tier": "auto",
    "messages": [
      {"role": "user", "content": "Опиши кратко стратегию retry для идемпотентных операций."}
    ]
  }'
```

Тот же запрос у клиента без фичи `priority_tier` — `400 priority_tier_not_enabled`.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 256,
    "service_tier": "auto",
    "messages": [
      {"role": "user", "content": "Тест"}
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

**Назначение.** Возможность запросить большой output у Claude: длинные отчёты, мульти-документная суммаризация, code-generation для крупных файлов, длинные batch-пайплайны.

**Параметр payload.** **Отдельного параметра нет.** Фича активируется автоматически, как только `max_tokens > 64 000` — шлюз сам добавляет соответствующий beta-header. В payload достаточно выставить нужный `max_tokens`.

**Лимиты по моделям.** Значения — из `config/llm.php → claude.model_capabilities` (`max_output` / `max_output_batch`).

| Модель | Sync `max_output` | Batch `max_output_batch` |
|--------|:-----------------:|:------------------------:|
| `claude-opus` | 128K | 300K |
| `claude-sonnet` | 64K | 300K |
| `claude-haiku` | 64K | 64K |

**Требование `allowed_features`.** Не требуется: это не клиентская фича, а capability модели. `allowed_features` при валидации `max_tokens` не проверяется.

**Beta-header.** При `max_tokens > 64 000` шлюз автоматически добавляет `anthropic-beta: output-300k-2026-03-24` (`config/llm.php → claude.beta_headers.output_300k`; логика — `PayloadBuilder::detectBetaFeatures`).

**Валидация.**

- **Sync / sync streaming / async / sessions.** Если `max_tokens` превышает `max_output` модели, `PayloadBuilder::enforceMaxTokensCap` бросает `PayloadBuildException::invalidRequest`, рендерится как `400 invalid_request_error` с сообщением формата `max_tokens (N) exceeds model <alias> maximum output (M)`. Специализированного `error.type` у этой ошибки нет — это обычный `invalid_request_error`, спецификой служит `message`.
- **Batch.** Если `max_tokens` превышает `max_output_batch` модели, `MessageRequestValidator::contextRulesCheck` добавляет ошибку `max_tokens_exceeds_batch_limit` с сообщением `max_tokens N exceeds batch limit M for <alias>`, HTTP 400.

**Несовместимости.**

- `claude-haiku` не поддерживает output > 64K даже в batch (общий лимит 64 000).
- `claude-sonnet` в sync-контексте ограничен 64K — большой output на Sonnet доступен только через Batch API (лимит 300K).

**Коды ошибок.**

| HTTP | `error.type` | Контекст | Триггер |
|------|-------------|----------|---------|
| 400 | `invalid_request_error` (без специального типа) | sync / stream / async / session | `max_tokens` > `max_output` модели. |
| 400 | `max_tokens_exceeds_batch_limit` | batch | `max_tokens` > `max_output_batch` модели. |

#### Примеры `curl`

Успешный sync-запрос на `claude-opus` с output до 120 000 токенов.

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 120000,
    "messages": [
      {"role": "user", "content": "Сгенерируй детальный технический отчёт объёмом около 100K токенов."}
    ]
  }'
```

Успешный batch-запрос на `claude-sonnet` с элементами до 250 000 токенов (полная структура batch-envelope — см. §7. Batch API).

```bash
curl -X POST https://gateway.example.com/api/v1/batches \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "requests": [
      {
        "custom_id": "report-1",
        "params": {
          "model": "claude-sonnet",
          "max_tokens": 250000,
          "messages": [
            {"role": "user", "content": "Собери мульти-документный отчёт..."}
          ]
        }
      }
    ]
  }'
```

Ошибочный sync-запрос на `claude-haiku` с `max_tokens: 100000` (лимит 64K).

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-haiku",
    "max_tokens": 100000,
    "messages": [
      {"role": "user", "content": "Тест"}
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

### 13.6 Справочник `allowed_features`

**Концепция.** `allowed_features` — JSON-колонка в таблице `clients`, словарь вида `<feature_key> → bool`. Она определяет, какие gated-возможности разрешены конкретному клиенту. В той же колонке могут жить и специальные ключи (например, `models` — whitelist алиасов), но эта секция ограничена dimension'ом feature-flag.

#### Включение/выключение через CLI

```bash
php artisan client:enable-feature {client_id} {feature}
php artisan client:disable-feature {client_id} {feature}
```

Обе команды знают ровно 11 ключей (порядок — как в `ClientEnableFeature::KNOWN_FEATURES`):

`thinking`, `web_search`, `code_execution`, `computer_use`, `bash`, `text_editor`, `priority_tier`, `citations`, `prompt_caching`, `structured_outputs`, `batch`.

Любое другое имя команда отклонит с сообщением `Unknown feature` и FAILURE exit-кодом.

#### Фичи вне CLI

**Следующие feature-keys проверяются шлюзом во время выполнения, но текущие команды `client:enable-feature` / `client:disable-feature` их не знают — они включаются только прямым обновлением JSON-колонки `allowed_features` у записи клиента (через `php artisan tinker` или SQL-миграцию): `mcp_connector`, `fast_mode`, `skills`, `web_fetch`, `tool_search`, `memory`, `inference_geo_override`, `allow_raw_anthropic_file_ids`.**

Пример включения фичи через `tinker`:

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

#### По умолчанию разрешены

`Authorization::DEFAULT_ALLOW_FEATURES` неявно включает две фичи, если ключ в `allowed_features` отсутствует:

- `prompt_caching`
- `citations`

Для всех остальных ключей отсутствие значения = deny.

#### Сводная таблица

| Ключ | Для чего | Как включить | Ошибка при отсутствии |
|------|----------|-------------|----------------------|
| `thinking` | Extended/adaptive thinking (§15) | CLI | `403 permission_error` |
| `web_search` | Server-tool `web_search` (§9) | CLI | `403 permission_error` |
| `web_fetch` | Server-tool `web_fetch` (§9) | прямое обновление `allowed_features` | `403 permission_error` |
| `code_execution` | Server-tool `code_execution*` (§9) | CLI | `403 permission_error`; при превышении free-hours — `429 quota_exhausted` |
| `computer_use` | Server-tool `computer` (§9) | CLI | `403 permission_error` |
| `bash` | Server-tool `bash` (§9) | CLI | `403 permission_error` |
| `text_editor` | Server-tool `text_editor` (§9) | CLI | `403 permission_error` |
| `tool_search` | Server-tool `tool_search_*` (§9) | прямое обновление `allowed_features` | `403 permission_error` |
| `memory` | Server-tool `memory` (§9) | прямое обновление `allowed_features` | `403 permission_error` |
| `priority_tier` | `service_tier: "auto"` (§13.4) | CLI | `400 priority_tier_not_enabled` |
| `fast_mode` | `speed: "fast"` (§13.2) | прямое обновление `allowed_features` | `400 fast_mode_not_enabled` |
| `mcp_connector` | `mcp_servers` (§13.3) | прямое обновление `allowed_features` | `400 mcp_connector_not_enabled` |
| `skills` | `/api/v1/skills/*` и поле `skills` в `/messages` (§13.1) | прямое обновление `allowed_features` | Skills API: `POST /skills` → `403 skill_creation_failed`, `GET /skills` → `403 skills_list_failed`, `DELETE /skills/{id}` → `403 skill_delete_failed`; `GET /skills/{id}` фичу не проверяет и для неизвестного id вернёт `404 skill_not_found`; `/messages` payload: `400 skills_not_enabled` |
| `citations` | Citations в ответах | CLI (включена по умолчанию; отключается `disable-feature`) | `403 permission_error` |
| `prompt_caching` | Prompt caching (§12) | CLI (включена по умолчанию; отключается `disable-feature`) | — (явной ошибки нет; кэш просто не активируется) |
| `structured_outputs` | `output_config` с JSON Schema | CLI | `403 permission_error` |
| `batch` | Доступ к Batch API (§7) | CLI | `403 permission_error` |
| `inference_geo_override` | Переопределение `inference_geo` в payload клиента | прямое обновление `allowed_features` | `400 invalid_request_error` (нарушение inference_geo в `PayloadBuilder`) |
| `allow_raw_anthropic_file_ids` | Использование Anthropic file_id напрямую, минуя gateway files | прямое обновление `allowed_features` | Ошибка резолвинга file source: `Unknown file ID format. Use gateway file IDs or enable allow_raw_anthropic_file_ids.` |

#### Три ветки ошибок при отсутствии фичи

- **Server-tool feature check.** `ServerFeaturesRule` → `FeatureNotAllowedException` → `bootstrap/app.php` → HTTP 403 `permission_error`. Применяется к серверным инструментам из §9.
- **Payload-level validator check.** `MessageRequestValidator::phase4Rules` формирует `ValidationError` для payload-полей `priority_tier`, `fast_mode`, `mcp_connector`, `skills` → HTTP 400 `invalid_request_error` с конкретным `error.type` (`priority_tier_not_enabled`, `fast_mode_not_enabled`, `mcp_connector_not_enabled`, `skills_not_enabled`).
- **Skills API enablement check.** `SkillsOrchestrator::assertSkillsEnabled` бросает `RuntimeException` с кодом 403, но **`SkillsController` сам ловит это исключение** и возвращает HTTP 403 с эндпоинт-специфичным `error.type`: `skill_creation_failed` (`POST /skills`), `skills_list_failed` (`GET /skills`), `skill_delete_failed` (`DELETE /skills/{id}`). Это **не `invalid_request_error` и не `permission_error`** — исключение не доходит до глобального рендера в `bootstrap/app.php`. Карвейт-аут: `GET /skills/{id}` в этой ветке **не участвует** — метод `show` не вызывает `assertSkillsEnabled`, и для клиента без фичи результатом будет `404 skill_not_found`, а не 403.

#### Квота `code_execution`

Помимо обычного deny-check, для серверного инструмента `code_execution` действует ограничение «бесплатных часов» в месяц — `config/llm.php → claude.pricing.server_tools.code_execution_free_hours_per_month` (сейчас `1550`). При исчерпании квоты `CodeExecutionUsageTracker` поднимает `FeatureQuotaExhaustedException`, который рендерится как `429 quota_exhausted`.

---

## 14. Выбор модели

### Сравнение моделей

| Характеристика | claude-opus | claude-sonnet | claude-haiku |
|---------------|:-----------:|:-------------:|:------------:|
| Snapshot | claude-opus-4-6 | claude-sonnet-4-6 | claude-haiku-4-5 |
| Контекстное окно | 1M | 1M | 200K |
| Макс. выход | 128K | 64K | 64K |
| Макс. выход (batch) | 300K | 300K | 64K |
| Adaptive thinking | да | да | нет |
| Compaction | да | да | нет |
| Prefill | нет | да | да |
| Fast mode | да | нет | нет |
| Min cache tokens | 1024 | 1024 | 2048 |

### Стоимость за 1M токенов

| | Input | Output | Cache write (5m) | Cache write (1h) | Cache read | Batch input | Batch output |
|-|:-----:|:------:|:----------------:|:----------------:|:----------:|:-----------:|:------------:|
| opus | $5.00 | $25.00 | $6.25 | $10.00 | $0.50 | $2.50 | $12.50 |
| sonnet | $3.00 | $15.00 | $3.75 | $6.00 | $0.30 | $1.50 | $7.50 |
| haiku | $1.00 | $5.00 | $1.25 | $2.00 | $0.10 | $0.50 | $2.50 |

Fast mode (только opus): множитель 6.0x к стоимости.

### Рекомендации по выбору

**claude-opus** -- сложные задачи: исследования, глубокий анализ, творческое письмо, задачи требующие длинного рассуждения. Максимальный объем выхода. Fast mode для приоритетной обработки.

**claude-sonnet** -- оптимальный баланс цена/качество. Программирование, суммаризация, аналитика, чат-боты. Модель по умолчанию.

**claude-haiku** -- быстрые и дешевые задачи: классификация, извлечение данных, простые ответы, фильтрация. Контекст 200K.

---

## 15. Adaptive thinking

Adaptive thinking (extended thinking) позволяет модели "думать" перед ответом, выделяя отдельный блок рассуждений.

### Поддерживаемые модели

- claude-opus
- claude-sonnet

claude-haiku НЕ поддерживает adaptive thinking.

### Уровни усилий

| Уровень | Описание |
|---------|----------|
| `low` | Минимальное размышление, быстрые ответы |
| `medium` | Умеренное размышление (по умолчанию) |
| `high` | Глубокое размышление для сложных задач |

### Активация

```json
{
  "model": "claude-sonnet",
  "max_tokens": 16000,
  "thinking": {
    "type": "enabled",
    "budget_tokens": 10000
  },
  "messages": [
    {"role": "user", "content": "Реши эту задачу по математике: ..."}
  ]
}
```

### Ответ с thinking

```json
{
  "content": [
    {
      "type": "thinking",
      "thinking": "Давайте разберем задачу по шагам..."
    },
    {
      "type": "text",
      "text": "Ответ: 42. Вот объяснение..."
    }
  ]
}
```

Блок `thinking` содержит внутренние рассуждения модели. Токены thinking учитываются в output.

### Потоковая передача thinking

При `stream: true` блоки thinking передаются через `content_block_start` и `content_block_delta` событий с `type: "thinking"`.

---

## 16. Compaction для длинных сессий

Compaction -- автоматическое сжатие истории диалога при приближении к лимиту контекстного окна.

### Поддерживаемые модели

- claude-opus
- claude-sonnet

claude-haiku НЕ поддерживает compaction.

### Как работает

Когда суммарный размер контекста приближается к лимиту окна, gateway автоматически активирует compaction:

1. Ранние сообщения сжимаются в краткое саммари.
2. Саммари помещается в начало контекста вместо оригинальных сообщений.
3. Недавние сообщения остаются без изменений.

### Что получает клиент

При использовании sessions клиент получает заголовок `X-Gateway-Warning: auto_resume_limit_reached`, сигнализирующий об активации compaction. Ответ при этом приходит стандартный -- compaction прозрачен для клиента.

### Активация через API

Compaction можно включить явно через beta-заголовок:

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

Gateway автоматически добавляет необходимые beta-заголовки для compaction.

---

## 17. Обработка ошибок

Формат ошибок gateway полностью совместим с Anthropic API. Классы ошибок SDK (`anthropic.BadRequestError`, `anthropic.AuthenticationError` и т.д.) работают без изменений.

### Формат ошибки

```json
{
  "type": "error",
  "error": {
    "type": "invalid_request_error",
    "message": "model: Snapshot names are not accepted. Use an alias: claude-opus, claude-sonnet, claude-haiku"
  }
}
```

### Коды ошибок

| HTTP | Тип | Описание | Рекомендация |
|:----:|-----|----------|-------------|
| 400 | `invalid_request_error` | Невалидный запрос (формат, параметры, snapshot-имя модели) | Исправить запрос |
| 401 | `authentication_error` | Невалидный или отсутствующий API-ключ | Проверить ключ |
| 402 | `billing_error` | Бюджет исчерпан | Пополнить бюджет |
| 403 | `permission_error` | Нет доступа к ресурсу | Проверить права |
| 404 | `not_found_error` | Ресурс не найден | Проверить ID |
| 429 | `rate_limit_error` | Превышен rate limit | Подождать и повторить |
| 500 | `api_error` | Внутренняя ошибка gateway | Повторить позже |
| 502 | `api_error` | Ошибка upstream (Anthropic) | Повторить позже |
| 503 | `overloaded_error` | Сервис перегружен | Exponential backoff |
| 504 | `timeout_error` | Таймаут запроса | Повторить или уменьшить max_tokens |

### Рекомендации по retry

| Код | Retry | Стратегия |
|:---:|:-----:|-----------|
| 400 | нет | Ошибка в запросе |
| 401 | нет | Проблема с аутентификацией |
| 402 | нет | Проблема с биллингом |
| 429 | да | Exponential backoff, учитывать `retry-after` |
| 500 | да | До 3 попыток с backoff |
| 502 | да | До 3 попыток с backoff |
| 503 | да | Exponential backoff, начиная с 5 секунд |
| 504 | да | Увеличить timeout или уменьшить задачу |

### Совместимость с SDK

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

try:
    message = client.messages.create(
        model="claude-sonnet",
        max_tokens=1024,
        messages=[{"role": "user", "content": "Привет!"}],
    )
except anthropic.BadRequestError as e:
    print(f"Невалидный запрос: {e.message}")
except anthropic.AuthenticationError:
    print("Проверьте API-ключ")
except anthropic.RateLimitError:
    print("Rate limit, повторите позже")
except anthropic.APIStatusError as e:
    print(f"Ошибка API: {e.status_code} {e.message}")
```

---

## 18. Rate limits

### Уровни ограничений

Gateway применяет rate limits на уровне API-ключа клиента. Лимит RPM (requests per minute) задается при создании клиента (по умолчанию 60).

### Заголовки rate limit

Gateway пробрасывает стандартные заголовки Anthropic rate limit:

| Заголовок | Описание |
|-----------|----------|
| `anthropic-ratelimit-requests-limit` | Лимит запросов |
| `anthropic-ratelimit-requests-remaining` | Оставшиеся запросы |
| `anthropic-ratelimit-requests-reset` | Время сброса |
| `anthropic-ratelimit-tokens-limit` | Лимит токенов |
| `anthropic-ratelimit-tokens-remaining` | Оставшиеся токены |
| `anthropic-ratelimit-tokens-reset` | Время сброса токенов |

Дополнительно gateway добавляет:

| Заголовок | Описание |
|-----------|----------|
| `X-Gateway-Spend-Remaining-USD` | Оставшийся бюджет в USD (или `unlimited`) |

### Обработка 429

При получении HTTP 429:

1. Прочитайте заголовок `retry-after` (если есть) -- количество секунд до разрешенного повтора.
2. Если `retry-after` отсутствует, используйте exponential backoff: 1s, 2s, 4s, 8s, 16s.
3. SDK автоматически обрабатывает retry для 429 (настраиваемо через `max_retries`).

```python
client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
    max_retries=3,
)
```

### ITPM / OTPM

Input Tokens Per Minute (ITPM) и Output Tokens Per Minute (OTPM) контролируются на стороне Anthropic. При их превышении Anthropic возвращает 429, который gateway пробрасывает клиенту. Использование prompt caching снижает эффективное потребление ITPM.

---

## 19. Полные примеры

### 18.1. Простой текстовый запрос

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

message = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    messages=[
        {"role": "user", "content": "Объясни разницу между TCP и UDP."}
    ],
)

print(message.content[0].text)
print(f"Tokens: {message.usage.input_tokens} in, {message.usage.output_tokens} out")
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "messages": [
      {"role": "user", "content": "Объясни разницу между TCP и UDP."}
    ]
  }'
```

---

### 18.2. Streaming

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

with client.messages.stream(
    model="claude-sonnet",
    max_tokens=2048,
    messages=[
        {"role": "user", "content": "Напиши пошаговый гайд по настройке Nginx."}
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
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -N \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 2048,
    "stream": true,
    "messages": [
      {"role": "user", "content": "Напиши пошаговый гайд по настройке Nginx."}
    ]
  }'
```

---

### 18.3. Vision (image URL)

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
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
                    "text": "Опиши, что изображено на графике.",
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
  -H "Authorization: Bearer gw_live_ваш_ключ" \
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
            "text": "Опиши, что изображено на графике."
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
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

# Загрузка файла
with open("screenshot.png", "rb") as f:
    uploaded = client.files.upload(file=f)

# Использование в запросе
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
                    "text": "Что изображено на скриншоте?",
                },
            ],
        }
    ],
)

print(message.content[0].text)
```

**curl:**

```bash
# Загрузка файла
FILE_ID=$(curl -s -X POST https://gateway.example.com/api/v1/files \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -F "file=@screenshot.png" | jq -r '.id')

# Использование в запросе
curl -X POST https://gateway.example.com/api/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
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
            "text": "Что изображено на скриншоте?"
          }
        ]
      }
    ]
  }'
```

---

### 18.5. PDF-документ

**Python SDK:**

```python
import anthropic
import base64

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
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
                    "text": "Суммаризируй ключевые выводы этого отчета.",
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
  -H "Authorization: Bearer gw_live_ваш_ключ" \
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
            "text": "Суммаризируй ключевые выводы этого отчета."
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
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

tools = [
    {
        "name": "get_stock_price",
        "description": "Получает текущую цену акции по тикеру.",
        "input_schema": {
            "type": "object",
            "properties": {
                "ticker": {"type": "string", "description": "Тикер акции (напр. AAPL)"},
            },
            "required": ["ticker"],
        },
    }
]

messages = [{"role": "user", "content": "Какая сейчас цена акций Apple?"}]

response = client.messages.create(
    model="claude-sonnet",
    max_tokens=1024,
    tools=tools,
    messages=messages,
)

if response.stop_reason == "tool_use":
    tool_block = next(b for b in response.content if b.type == "tool_use")
    print(f"Tool call: {tool_block.name}({json.dumps(tool_block.input)})")

    # Клиент вызывает свой API и возвращает результат
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
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 1024,
    "tools": [
      {
        "name": "get_stock_price",
        "description": "Получает текущую цену акции по тикеру.",
        "input_schema": {
          "type": "object",
          "properties": {
            "ticker": {"type": "string", "description": "Тикер акции"}
          },
          "required": ["ticker"]
        }
      }
    ],
    "messages": [
      {"role": "user", "content": "Какая сейчас цена акций Apple?"}
    ]
  }'
```

---

### 18.7. Web search tool

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
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
        {"role": "user", "content": "Какие последние новости о квантовых компьютерах?"}
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
  -H "Authorization: Bearer gw_live_ваш_ключ" \
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
      {"role": "user", "content": "Какие последние новости о квантовых компьютерах?"}
    ]
  }'
```

---

### 18.8. Prompt caching (manual breakpoint)

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

long_system = "Ты эксперт-юрист. Вот полный текст закона: " + "..." * 500

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
        {"role": "user", "content": "Какие штрафы предусмотрены статьей 12?"}
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
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-sonnet",
    "max_tokens": 2048,
    "system": [
      {
        "type": "text",
        "text": "Ты эксперт-юрист. Вот полный текст закона: ...(длинный текст)...",
        "cache_control": {"type": "ephemeral"}
      }
    ],
    "messages": [
      {"role": "user", "content": "Какие штрафы предусмотрены статьей 12?"}
    ]
  }'
```

---

### 18.9. Token counting

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
    base_url="https://gateway.example.com/api/v1",
)

result = client.messages.count_tokens(
    model="claude-sonnet",
    messages=[
        {
            "role": "user",
            "content": "Напиши детальный обзор архитектуры микросервисов.",
        }
    ],
)

print(f"Input tokens: {result.input_tokens}")
```

**curl:**

```bash
curl -X POST https://gateway.example.com/api/v1/messages/count_tokens \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-sonnet",
    "messages": [
      {"role": "user", "content": "Напиши детальный обзор архитектуры микросервисов."}
    ]
  }'
```

---

### 18.10. Batch submission

**Python SDK:**

```python
import anthropic

client = anthropic.Anthropic(
    api_key="gw_live_ваш_ключ",
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
                    {"role": "user", "content": f"Переведи на английский: '{text}'"}
                ],
            },
        }
        for i, text in enumerate([
            "Привет, мир!",
            "Как дела?",
            "Спасибо за помощь.",
            "До свидания.",
        ])
    ]
)

print(f"Batch ID: {batch.id}")
print(f"Status: {batch.processing_status}")

# Ожидание результатов (polling)
import time
while True:
    status = client.messages.batches.retrieve(batch.id)
    if status.processing_status == "ended":
        break
    time.sleep(10)

# Получение результатов
for result in client.messages.batches.results(batch.id):
    print(f"{result.custom_id}: {result.result.message.content[0].text}")
```

**curl:**

```bash
# Создание batch
curl -X POST https://gateway.example.com/api/v1/batches \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "requests": [
      {
        "custom_id": "translate-0",
        "params": {
          "model": "claude-haiku",
          "max_tokens": 512,
          "messages": [{"role": "user", "content": "Переведи на английский: Привет, мир!"}]
        }
      },
      {
        "custom_id": "translate-1",
        "params": {
          "model": "claude-haiku",
          "max_tokens": 512,
          "messages": [{"role": "user", "content": "Переведи на английский: Как дела?"}]
        }
      }
    ]
  }'

# Проверка статуса
curl https://gateway.example.com/api/v1/batches/bat_ID \
  -H "Authorization: Bearer gw_live_ваш_ключ"

# Получение результатов
curl https://gateway.example.com/api/v1/batches/bat_ID/results \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

---

### 18.11. Session-based chat

**Python SDK:**

```python
import httpx

BASE = "https://gateway.example.com/api/v1"
HEADERS = {
    "Authorization": "Bearer gw_live_ваш_ключ",
    "Content-Type": "application/json",
}

# Создание сессии
resp = httpx.post(f"{BASE}/sessions", headers=HEADERS, json={
    "model": "claude-sonnet",
    "system": "Ты ассистент-программист на Python.",
    "max_tokens": 4096,
})
session = resp.json()
session_id = session["session_id"]
print(f"Session: {session_id}")

# Первое сообщение
resp = httpx.post(f"{BASE}/sessions/{session_id}/messages", headers=HEADERS, json={
    "content": "Как реализовать singleton на Python?",
})
print(resp.json()["content"][0]["text"])

# Второе сообщение (контекст сохранен)
resp = httpx.post(f"{BASE}/sessions/{session_id}/messages", headers=HEADERS, json={
    "content": "А теперь покажи thread-safe вариант.",
})
print(resp.json()["content"][0]["text"])

# История сессии
resp = httpx.get(f"{BASE}/sessions/{session_id}/messages", headers=HEADERS)
for msg in resp.json():
    print(f"[{msg['role']}]: {msg['content'][:80]}...")
```

**curl:**

```bash
# Создание сессии
SESSION_ID=$(curl -s -X POST https://gateway.example.com/api/v1/sessions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{"model": "claude-sonnet", "system": "Ты ассистент.", "max_tokens": 4096}' \
  | jq -r '.session_id')

# Первое сообщение
curl -X POST "https://gateway.example.com/api/v1/sessions/$SESSION_ID/messages" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{"content": "Как реализовать singleton?"}'

# Второе сообщение
curl -X POST "https://gateway.example.com/api/v1/sessions/$SESSION_ID/messages" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{"content": "А теперь покажи thread-safe вариант."}'
```

---

### 18.12. Async + webhook

**curl:**

```bash
# Отправка async-запроса (callback_url должен быть в whitelist клиента)
curl -X POST https://gateway.example.com/api/v1/messages/async \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 8192,
    "messages": [
      {"role": "user", "content": "Напиши детальный анализ текущего состояния рынка AI."}
    ],
    "callback_url": "https://your-api.example.com/api/llm-callback"
  }'

# Ответ: 202 Accepted
# {
#   "request_id": "req_abc123def456ghi789jkl012",
#   "status": "accepted",
#   "estimated_cost_usd": 0.032100,
#   "estimate_mode": "character_based",
#   "callback_url": "https://your-api.example.com/api/llm-callback",
#   "expires_at": "2026-04-15T10:00:00+00:00"
# }

# Опциональный polling (вместо ожидания webhook)
curl https://gateway.example.com/api/v1/messages/req_abc123def456ghi789jkl012 \
  -H "Authorization: Bearer gw_live_ваш_ключ"
```

Webhook получит wrapped envelope (см. раздел 6) с подписью `X-Gateway-Signature`.

Пример обработчика на стороне клиента (Python / Flask):

```python
import hmac
import hashlib
from flask import Flask, request, jsonify

app = Flask(__name__)
SIGNING_SECRET = "ваш_signing_secret"

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
