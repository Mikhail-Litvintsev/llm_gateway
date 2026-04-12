# LLM Gateway v4 -- Руководство по интеграции

- **Версия протокола:** 4.0
- **Формат:** JSON (Anthropic Messages API)
- **Провайдер:** Claude (Anthropic)
- **Дата актуализации:** 2026-04-12

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
docker exec llm_gateway php artisan llm:create-client my_service --rate-limit=120
```

При создании выводятся **API Key** и **Signing Secret** (для верификации webhook-подписей). Оба значения отображаются один раз -- сохраните их немедленно.

### Ротация ключей

```bash
docker exec llm_gateway php artisan llm:rotate-key my_service --ttl=24
```

После ротации старый ключ продолжает работать в течение grace period (по умолчанию 86400 секунд = 24 часа). Новый ключ выводится в консоль. Это позволяет обновить ключ на стороне клиента без простоя.

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
  "webhook_url": "https://your-api.example.com/api/llm-callback"
}
```

### Ответ (202 Accepted)

```json
{
  "request_id": "req_abc123def456ghi789jkl012",
  "status": "queued",
  "message": "Request accepted for async processing"
}
```

Заголовок: `X-Gateway-Request-Id: req_abc123def456ghi789jkl012`

### Получение результата по ID

Вместо ожидания webhook можно опросить статус:

```
GET /api/v1/messages/{requestId}
Authorization: Bearer gw_live_ваш_ключ
```

### Доставка webhook

Gateway доставляет результат на `webhook_url` в виде **wrapped envelope** -- оригинальный ответ Anthropic обернут в конверт с метаданными gateway:

```json
{
  "request_id": "req_abc123def456ghi789jkl012",
  "status": "completed",
  "model_alias": "claude-opus",
  "model_snapshot": "claude-opus-4-6",
  "cost_usd": 0.015430,
  "response": {
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
  }
}
```

### HMAC-подпись webhook

Каждый webhook подписан HMAC-SHA256 с использованием signing secret клиента. Подпись передается в заголовке:

```
X-Gateway-Signature: sha256=a1b2c3d4e5f6...
```

### Валидация подписи (Python)

```python
import hmac
import hashlib

def verify_webhook(body: bytes, signature_header: str, secret: str) -> bool:
    expected = "sha256=" + hmac.new(
        secret.encode(), body, hashlib.sha256
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

При приближении к лимиту контекстного окна gateway автоматически применяет compaction -- сжатие ранних сообщений в краткое саммари. Клиент получает заголовок `X-Gateway-Warning: auto_resume_limit_reached`, когда compaction активирован. Подробнее -- раздел 15.

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

MCP (Model Context Protocol) позволяет подключать внешние серверы инструментов. Gateway автоматически добавляет beta-заголовок `mcp-client-2025-11-20`.

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

## 13. Выбор модели

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

## 14. Adaptive thinking

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

## 15. Compaction для длинных сессий

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

## 16. Обработка ошибок

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

## 17. Rate limits

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

## 18. Полные примеры

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
# Отправка async-запроса
curl -X POST https://gateway.example.com/api/v1/messages/async \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer gw_live_ваш_ключ" \
  -d '{
    "model": "claude-opus",
    "max_tokens": 8192,
    "messages": [
      {"role": "user", "content": "Напиши детальный анализ текущего состояния рынка AI."}
    ],
    "webhook_url": "https://your-api.example.com/api/llm-callback"
  }'

# Ответ: 202 Accepted
# {
#   "request_id": "req_abc123def456ghi789jkl012",
#   "status": "queued",
#   "message": "Request accepted for async processing"
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
    signature = request.headers.get("X-Gateway-Signature", "")
    expected = "sha256=" + hmac.new(
        SIGNING_SECRET.encode(), request.data, hashlib.sha256
    ).hexdigest()

    if not hmac.compare_digest(expected, signature):
        return jsonify({"error": "Invalid signature"}), 401

    payload = request.json
    request_id = payload["request_id"]
    status = payload["status"]

    if status == "completed":
        response = payload["response"]
        text = response["content"][0]["text"]
        print(f"[{request_id}] Result: {text[:200]}...")
    elif status == "error":
        print(f"[{request_id}] Error: {payload.get('error')}")

    return jsonify({"ok": True}), 200
```
