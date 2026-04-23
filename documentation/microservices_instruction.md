# Подключение к LLM Gateway из другого микросервиса

## Сеть

Общая Docker-сеть: `microservices-llm` (external).

В `docker-compose.yml` вашего сервиса:

```yaml
networks:
  microservices-llm:
    external: true

services:
  your-service:
    networks:
      - microservices-llm
      - your_internal_network
```

## Доступные эндпоинты LLM Gateway

| Hostname в сети | Порт (внутренний) | Описание |
|-----------------|-------------------|----------|
| `llm_nginx` | `80` | API Gateway (Nginx → PHP-FPM) |
| `llm_queue_worker` | — | Обработка очереди (не слушает порт) |

Базовый URL для вызова из Docker-сети:

```
http://llm_nginx:80
```

### API endpoints

| Метод | URL | Описание |
|-------|-----|----------|
| POST | `/api/v1/llm/request` | Отправка запроса к LLM |
| GET | `/internal/health` | Healthcheck |
| GET | `/internal/stats?from=2026-01-01&to=2026-03-18` | Статистика |

## Обратная связь (LLM Gateway → ваш сервис)

LLM Gateway доставляет ответы через callback. Контейнеры `llm_queue_worker` и `llm_nginx` подключены к `microservices-llm`, поэтому callback может быть направлен на ваш сервис по его `container_name`:

```
https://your_container_name:port/your/callback/endpoint
```

Callback URL должен использовать `https://` и быть предварительно зарегистрирован в whitelist клиента.

**В dev/docker среде** для тестирования можно отключить проверку HTTPS, если dev_mode включен для клиента.

## Заголовки запроса к LLM Gateway

```
Content-Type: application/xml; charset=utf-8
Authorization: Bearer <api_key>
X-Idempotency-Key: <optional, unique string up to 256 chars>
```

## Минимальный пример запроса

```bash
curl -X POST http://llm_nginx:80/api/v1/llm/request \
  -H "Content-Type: application/xml; charset=utf-8" \
  -H "Authorization: Bearer <api_key>" \
  -d '<?xml version="1.0" encoding="UTF-8"?>
<llm_request version="3.0">
  <meta>
    <request_id>req_001</request_id>
    <priority>normal</priority>
  </meta>
  <provider>
    <name>claude</name>
  </provider>
  <prompt>
    <block type="instruction" role="user">Ответь на вопрос кратко.</block>
  </prompt>
  <callback>
    <url>https://your_container:port/api/llm-response</url>
    <timeout>120</timeout>
  </callback>
</llm_request>'
```

Ответ — HTTP 202:

```json
{
  "status": "accepted",
  "request_id": "req_001",
  "provider": {"name": "claude", "model": "claude-sonnet-4-6"},
  "callback_url": "https://your_container:port/api/llm-response"
}
```

Результат LLM придет на callback URL асинхронно.

## Structured Output (JSON Schema)

Для получения ответа LLM в строго определенном JSON-формате используйте `response_format` с типом `json_schema`:

### Пример запроса с JSON Schema

```xml
<parameters>
  <temperature>0.0</temperature>
  <max_tokens>2048</max_tokens>
  <response_format>
    <type>json_schema</type>
    <name>trade_recommendation</name>
    <strict>true</strict>
    <schema><![CDATA[
      {
        "type": "object",
        "properties": {
          "action": {"type": "string", "enum": ["LONG", "SHORT", "NO_TRADE"]},
          "confidence": {"type": "number"},
          "reasoning": {"type": "string"}
        },
        "required": ["action", "confidence", "reasoning"],
        "additionalProperties": false
      }
    ]]></schema>
  </response_format>
</parameters>
```

### Поведение по провайдерам

- **OpenAI, Claude, Gemini, Mistral** — schema передается нативно через API провайдера. Провайдер гарантирует соответствие ответа схеме.
- **DeepSeek** — schema инъецируется в system prompt как текстовая инструкция. В callback-ответе поле `structured_output_fallback` будет `true`. Валидация ответа LLM на соответствие схеме — ответственность клиента.

### Поля callback при structured output

| Поле | Тип | Описание |
|------|-----|----------|
| `structured_output_fallback` | boolean | `true` если structured output был эмулирован (не нативный). Клиент должен самостоятельно валидировать ответ |

## Формат callback-ответа

Заголовки:
```
Content-Type: application/json; charset=utf-8
X-LLM-Signature: sha256=<HMAC-SHA256 hex>
X-LLM-Timestamp: <unix timestamp>
X-LLM-Nonce: <uuid v4>
X-LLM-Request-Id: req_001
X-LLM-Event-Type: completion | error
```

Тело (успех):
```json
{
  "status": "ok",
  "meta": {"request_id": "req_001"},
  "provider": {"name": "claude", "model": "claude-sonnet-4-6", "is_fallback": false},
  "result": {
    "content": "Ответ модели...",
    "tool_calls": [],
    "finish_reason": "end_turn",
    "usage": {"input_tokens": 100, "output_tokens": 50}
  },
  "latency_ms": 3200
}
```

Тело (ошибка):
```json
{
  "status": "error",
  "meta": {"request_id": "req_001"},
  "error": {"code": "PROVIDER_TIMEOUT", "message": "..."},
  "latency_ms": 30000
}
```

## Верификация подписи callback

Алгоритм (на стороне вашего сервиса):

1. Извлечь `X-LLM-Timestamp`, `X-LLM-Nonce`, `X-LLM-Signature` из заголовков
2. Проверить что timestamp не старше 300 секунд
3. Сформировать строку: `{timestamp}.{nonce}.{raw_body}`
4. Вычислить `hash_hmac('sha256', string, signing_secret)`
5. Сравнить `sha256={hmac}` с `X-LLM-Signature` (timing-safe)

`signing_secret` выдается при создании API-клиента.

## Занятые порты на хосте

### LLM Gateway

| Порт | Сервис |
|------|--------|
| 8080 | Nginx (HTTP API) |
| 3307 | MySQL |
| 6381 | Redis |

### ai_prediction (сосед по сети)

| Порт | Сервис |
|------|--------|
| 3000 | Frontend (Vue 3) |
| 3306 | MySQL |
| 6380 | Redis |
| 8000 | Backend (Laravel) |
| 8123 | ClickHouse HTTP |
| 9001 | ClickHouse Native |

При добавлении новых сервисов — избегать всех перечисленных портов.

## Доступ к ai_prediction из LLM Gateway

```
http://aip_backend:8000/api/...
```

## Доступ к LLM Gateway из ai_prediction

```
http://llm_nginx:80/api/v1/llm/request
```

## Регистрация клиента

Выполняется на хосте или внутри контейнера `llm_gateway`:

```bash
docker exec -it llm_gateway php artisan llm:create-client my_service --rate-limit=120 --providers=claude,openai
docker exec -it llm_gateway php artisan llm:add-callback-url my_service https://your_container:port/api/llm-response
```

Сохраните `API Key` и `Signing Secret` из вывода команды.
