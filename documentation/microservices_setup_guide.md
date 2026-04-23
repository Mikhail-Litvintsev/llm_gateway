# Настройка микросервисной интеграции с LLM Gateway

Руководство по подключению вашего микросервиса к LLM Gateway в Docker-среде.

**Дата актуализации:** 2026-04-02

---

## 1. Docker-сеть

LLM Gateway использует внешнюю Docker-сеть `microservices-llm`. Все контейнеры, которым нужен доступ к шлюзу, должны быть подключены к этой сети.

Сеть создается автоматически при запуске LLM Gateway через `start.sh`, но можно создать вручную:

```bash
docker network create microservices-llm
```

### Подключение вашего сервиса

В `docker-compose.yml` вашего микросервиса:

```yaml
networks:
  microservices-llm:
    external: true

services:
  your_backend:
    container_name: your_backend
    networks:
      - microservices-llm
      - your_internal_network
    # ...
```

---

## 2. Контейнеры LLM Gateway

| Контейнер | Назначение | Доступ по сети |
|-----------|-----------|----------------|
| `llm_nginx` | Reverse proxy (Nginx -> PHP-FPM) | `http://llm_nginx:80` |
| `llm_gateway` | PHP-FPM (приложение) | Только через nginx |
| `llm_queue_worker` | Обработка очередей | Подключен к `microservices-llm` (для callback) |
| `llm_scheduler` | Планировщик задач | Только внутренняя сеть |
| `llm_mysql` | MySQL 8.4 | Только внутренняя сеть |
| `llm_redis` | Redis 7 | Только внутренняя сеть |

**Базовый URL из Docker-сети:**

```
http://llm_nginx:80
```

---

## 3. API endpoints

| Метод | URL | Middleware | Описание |
|-------|-----|-----------|----------|
| POST | `/api/v1/llm/request` | auth.api_key, rate.api_key | Отправка запроса к LLM |
| GET | `/api/v1/llm/requests/{requestId}/raw-responses` | auth.api_key, rate.api_key | Получение raw-ответов провайдера |
| GET | `/internal/health` | internal.network | Healthcheck |
| GET | `/internal/stats?from=2026-01-01&to=2026-04-02` | internal.network | Статистика |

**Internal endpoints** доступны только из приватных сетей (172.16.0.0/12, 10.0.0.0/8, 192.168.0.0/16, 127.0.0.1).

---

## 4. Регистрация клиента

Выполняется внутри контейнера `llm_gateway`:

```bash
# Создать клиента с ограничением провайдеров и rate limit
docker exec llm_gateway php artisan llm:create-client my_service \
  --rate-limit=120 \
  --providers=claude,openai \
  --no-dev-mode

# Добавить callback URL в whitelist
docker exec llm_gateway php artisan llm:add-callback-url my_service \
  https://your_backend:8000/api/llm-response
```

**Сохраните из вывода:**
- `API Key` -- для заголовка `Authorization: Bearer <api_key>`
- `Signing Secret` -- для верификации подписи callback

### HTTPS и callback URL

- В production callback URL **обязательно** `https://`.
- В dev/docker-окружении (APP_ENV=local) допускается `http://` -- для межконтейнерного взаимодействия без TLS.

### dev_mode

Новые клиенты создаются с `dev_mode=true` (если не указан `--no-dev-mode`). В dev_mode запросы не отправляются к реальным провайдерам -- возвращается stub-ответ. Для отключения:

```bash
docker exec llm_gateway php artisan llm:toggle-dev-mode my_service --disable
```

---

## 5. Обратная связь (callback)

LLM Gateway доставляет ответы через callback. Контейнеры `llm_queue_worker` и `llm_nginx` подключены к `microservices-llm`, поэтому callback направляется на ваш сервис по его `container_name`:

```
http://your_backend:8000/api/llm-response
```

### Требования к callback endpoint

1. Принимать `Content-Type: application/json; charset=utf-8`
2. Метод: POST (или PUT, если указан в запросе)
3. Отвечать HTTP 200 или 202 в течение **10 секунд**
4. Быть идемпотентным по `request_id`
5. Верифицировать подпись (см. раздел 9)

### Заголовки callback

```
Content-Type: application/json; charset=utf-8
X-LLM-Signature: sha256=<HMAC-SHA256 hex>
X-LLM-Timestamp: <unix timestamp>
X-LLM-Nonce: <uuid v4>
X-LLM-Request-Id: <request_id>
X-LLM-Event-Type: completion | error | stream_token | stream_done | stream_error
```

### Формат callback -- успех

```json
{
  "status": "ok",
  "meta": {"request_id": "req_001", "...": "..."},
  "provider": {"name": "claude", "model": "claude-sonnet-4-6", "is_fallback": false},
  "result": {
    "content": "Ответ модели...",
    "tool_calls": [],
    "finish_reason": "end_turn",
    "usage": {"input_tokens": 100, "output_tokens": 50, "cache_creation_input_tokens": 0, "cache_read_input_tokens": 0}
  },
  "structured_output_fallback": false,
  "latency_ms": 3200
}
```

### Формат callback -- ошибка

```json
{
  "status": "error",
  "meta": {"request_id": "req_001"},
  "error": {"code": "PROVIDER_TIMEOUT", "message": "...", "details": {}},
  "latency_ms": 30000
}
```

---

## 6. Минимальный пример запроса

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
    <url>http://your_backend:8000/api/llm-response</url>
    <timeout>120</timeout>
  </callback>
</llm_request>'
```

Ответ -- HTTP 202:

```json
{
  "status": "accepted",
  "request_id": "req_001",
  "meta": {"request_id": "req_001", "priority": "normal"},
  "provider": {"name": "claude", "model": "claude-sonnet-4-6"},
  "callback_url": "http://your_backend:8000/api/llm-response",
  "dev_mode": false
}
```

---

## 7. Structured Output (JSON Schema)

Для получения ответа в строго определенном JSON-формате:

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

| Провайдер | json_schema | Примечание |
|-----------|:-----------:|-----------|
| OpenAI, Claude, Gemini, Mistral | нативно | Провайдер гарантирует соответствие |
| DeepSeek | эмуляция | Schema инъецируется в system prompt. Поле `structured_output_fallback: true` в callback. Валидация -- ответственность клиента |

---

## 8. Healthcheck

Для мониторинга доступности LLM Gateway в вашем `docker-compose.yml`:

```yaml
services:
  your_backend:
    depends_on:
      llm_nginx:
        condition: service_healthy
```

Или проверка из кода:

```bash
curl http://llm_nginx:80/internal/health
```

Ответ:

```json
{
  "status": "ok",
  "queue_size": 0,
  "pending_prompts": 0,
  "pending_prompts_expired": 0,
  "pending_responses": 0,
  "pending_responses_failed": 0,
  "last_request_at": "2026-04-02T10:15:30.000000Z"
}
```

---

## 9. Верификация подписи callback

Каждый callback подписан HMAC-SHA256 с использованием `signing_secret` вашего клиента.

### Алгоритм

1. Извлечь `X-LLM-Timestamp`, `X-LLM-Nonce`, `X-LLM-Signature` из заголовков.
2. Проверить что timestamp не старше **300 секунд**.
3. Сформировать строку: `{timestamp}.{nonce}.{raw_body}`.
4. Вычислить: `hash_hmac('sha256', string, signing_secret)`.
5. Сравнить `sha256={hmac}` с `X-LLM-Signature` (timing-safe).

### Пример на PHP (Laravel)

```php
public function handleCallback(Request $request): JsonResponse
{
    $timestamp = $request->header('X-LLM-Timestamp');
    $nonce = $request->header('X-LLM-Nonce');
    $signature = $request->header('X-LLM-Signature');

    // Защита от replay
    if (abs(time() - (int) $timestamp) > 300) {
        return response()->json(['error' => 'Timestamp expired'], 401);
    }

    $payload = "{$timestamp}.{$nonce}.{$request->getContent()}";
    $expected = 'sha256=' . hash_hmac('sha256', $payload, config('services.llm.signing_secret'));

    if (! hash_equals($expected, $signature)) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }

    // Обработка ответа
    $data = $request->json()->all();
    $eventType = $request->header('X-LLM-Event-Type');
    $requestId = $request->header('X-LLM-Request-Id');

    if ($data['status'] === 'ok') {
        // Успешный ответ LLM
        $content = $data['result']['content'];
        $toolCalls = $data['result']['tool_calls'];
        // ...
    } else {
        // Ошибка
        $errorCode = $data['error']['code'];
        // ...
    }

    return response()->json(['ok' => true]);
}
```

### Пример на Python

```python
import hmac
import hashlib
import time

def verify_callback(headers: dict, body: bytes, signing_secret: str) -> bool:
    timestamp = headers.get('X-LLM-Timestamp', '')
    nonce = headers.get('X-LLM-Nonce', '')
    signature = headers.get('X-LLM-Signature', '')

    if abs(time.time() - int(timestamp)) > 300:
        return False

    payload = f"{timestamp}.{nonce}.{body.decode('utf-8')}"
    expected = 'sha256=' + hmac.new(
        signing_secret.encode(), payload.encode(), hashlib.sha256
    ).hexdigest()

    return hmac.compare_digest(expected, signature)
```

---

## 10. Занятые порты на хосте

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

**При добавлении новых сервисов -- избегать всех перечисленных портов.**

---

## 11. Доступ между сервисами

### Из вашего сервиса в LLM Gateway

```
http://llm_nginx:80/api/v1/llm/request
```

### Из LLM Gateway в ai_prediction

```
http://aip_backend:8000/api/...
```

### Из ai_prediction в LLM Gateway

```
http://llm_nginx:80/api/v1/llm/request
```

---

## 12. Управление провайдерами

Полезные команды администратора:

```bash
# Статус всех провайдеров (active/paused)
docker exec llm_gateway php artisan llm:provider-status

# Возобновить приостановленный провайдер
docker exec llm_gateway php artisan llm:resume-provider claude

# Возобновить все
docker exec llm_gateway php artisan llm:resume-provider all

# Статистика запросов
docker exec llm_gateway php artisan llm:stats --from=2026-04-01 --to=2026-04-02

# Бюджет токенов Claude
docker exec llm_gateway php artisan llm:claude-token-budget

# Ротация ключей (старый работает еще 24 часа)
docker exec llm_gateway php artisan llm:rotate-key my_service --ttl=24
```

Провайдер автоматически ставится на паузу при:
- HTTP 429 (rate limit) -- временная пауза с авторезюмом.
- HTTP 402 (insufficient funds) -- постоянная пауза, требуется `llm:resume-provider`.

Запросы к приостановленному провайдеру не теряются -- ожидают в очереди.

---

## 13. Запуск и остановка

```bash
# Запуск (создает сеть, поднимает контейнеры, мигрирует БД)
cd /path/to/llm_gateway && ./start.sh

# Остановка
./stop.sh
```

---

## 14. Чеклист интеграции

- [ ] Создан Docker-сервис подключенный к `microservices-llm`
- [ ] Зарегистрирован клиент через `llm:create-client`
- [ ] Callback URL добавлен в whitelist через `llm:add-callback-url`
- [ ] Сохранены API Key и Signing Secret
- [ ] dev_mode отключен для production-использования
- [ ] Callback endpoint реализован: принимает JSON, отвечает 200/202 за <10с
- [ ] Верификация подписи реализована (HMAC-SHA256)
- [ ] Обработка error-callback реализована
- [ ] Идемпотентность callback endpoint обеспечена
- [ ] Порты не конфликтуют с существующими сервисами
