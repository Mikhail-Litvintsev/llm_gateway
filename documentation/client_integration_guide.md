# LLM Gateway -- Полное руководство по интеграции для клиентов

- **Версия протокола:** 3.0
- **Формат запросов:** XML
- **Формат ответов:** JSON
- **Дата актуализации:** 2026-04-02

---

## 1. Обзор

LLM Gateway -- шлюз-посредник для работы с LLM-провайдерами. Принимает запрос в формате XML, преобразует в нативный API провайдера и доставляет результат клиенту **исключительно через асинхронный callback**.

**Ключевые принципы:**

- Синхронный ответ сервиса -- только подтверждение приема или ошибка валидации.
- Результат LLM приходит на callback URL (POST/PUT с JSON).
- API провайдеров не поддерживают серверные сессии. Клиент управляет историей диалога через блоки `history` в каждом запросе.
- Клиент идентифицируется через API-ключ в заголовке `Authorization`.

---

## 2. Регистрация клиента

Регистрация выполняется администратором сервиса:

```bash
# Создание клиента
docker exec llm_gateway php artisan llm:create-client my_service \
  --rate-limit=120 \
  --providers=claude,openai

# Добавление callback URL в whitelist
docker exec llm_gateway php artisan llm:add-callback-url my_service \
  https://your-api.example.com/api/llm-response
```

При создании выводятся:
- **API Key** -- для авторизации запросов (не извлекается повторно).
- **Signing Secret** -- для верификации callback-подписи (не извлекается повторно).

Сохраните оба значения надежно.

### Параметры клиента

| Параметр | Значение по умолчанию | Описание |
|----------|:---------------------:|----------|
| `rate_limit` | 60 | Максимум запросов в минуту |
| `allowed_providers` | все | Ограничение по провайдерам (через запятую: `claude,openai`) |
| `dev_mode` | включен | Возвращает stub-ответы без обращения к провайдерам |

### Ротация ключей

```bash
docker exec llm_gateway php artisan llm:rotate-key my_service --ttl=24
```

Старый ключ продолжает работать в течение TTL (по умолчанию 24 часа). Новый ключ выводится в консоль.

### Управление dev_mode

```bash
docker exec llm_gateway php artisan llm:toggle-dev-mode my_service --disable
```

В dev_mode запросы не отправляются к реальным провайдерам. Возвращается stub-ответ:
- provider: `stub`, model: `dev-mode-stub`
- content: `"This is a dev_mode stub response."`
- latency: ~150 мс (настраивается)

---

## 3. Endpoint

| Параметр | Значение |
|----------|----------|
| Метод | `POST` |
| URL | `/api/v1/llm/request` |
| Content-Type | `application/xml; charset=utf-8` |
| Максимальный размер тела | 50 МБ |

### Заголовки запроса

| Заголовок | Обязательный | Описание |
|-----------|:------------:|----------|
| `Content-Type` | да | `application/xml; charset=utf-8` |
| `Authorization` | да | `Bearer <api_key>` |
| `X-Idempotency-Key` | нет | Строка до 256 символов. Повторный запрос с тем же ключом в течение 24 часов возвращает кешированный ответ |
| `X-Request-Id` | нет | UUID v4 для трейсинга |

---

## 4. Структура XML-запроса

```xml
<?xml version="1.0" encoding="UTF-8"?>
<llm_request version="3.0">
  <meta>...</meta>             <!-- Обязательно -->
  <provider>...</provider>     <!-- Необязательно -->
  <prompt>...</prompt>         <!-- Обязательно -->
  <tools>...</tools>           <!-- Необязательно -->
  <parameters>...</parameters> <!-- Необязательно -->
  <callback>...</callback>     <!-- Обязательно -->
</llm_request>
```

**Правила:**
- Атрибут `version="3.0"` обязателен.
- Кодировка -- строго UTF-8.
- Обязательные секции: `<meta>`, `<prompt>`, `<callback>`.

---

## 5. Секция `<meta>`

```xml
<meta>
  <request_id>req_001</request_id>
  <session_id>sess_abc</session_id>
  <step_id>1</step_id>
  <timestamp>2026-03-18T10:00:00Z</timestamp>
  <source>my_bot</source>
  <user_id>u_123</user_id>
  <priority>normal</priority>
  <custom_field>любое_значение</custom_field>
</meta>
```

### Поля

| Элемент | Обязательный | Тип | Описание |
|---------|:------------:|-----|----------|
| `request_id` | да | string | Уникальный ID запроса (до 256 символов) |
| `session_id` | нет | string | ID сессии диалога (до 256 символов) |
| `step_id` | условно | integer | Номер хода в сессии. **Обязателен при наличии `session_id`**. Должен быть строго больше предыдущего для данной сессии |
| `timestamp` | нет | string | ISO 8601 (UTC). Если не указан -- текущее время |
| `source` | нет | string | Источник запроса |
| `user_id` | нет | string | ID конечного пользователя. Передается в LLM API для модерации |
| `priority` | нет | enum | `low`, `normal` (по умолчанию), `high`. Влияет на приоритет в очереди |

Любые другие элементы внутри `<meta>` сохраняются без изменений и возвращаются в callback-ответе.

---

## 6. Секция `<provider>`

Выбор провайдера, модели и fallback-цепочки. **Необязательна.**

```xml
<provider>
  <name>claude</name>
  <model>claude-sonnet-4-6</model>
  <fallback>
    <name>openai</name>
    <model>gpt-4o</model>
    <fallback>
      <name>gemini</name>
    </fallback>
  </fallback>
</provider>
```

### Доступные провайдеры

| Имя | Модель по умолчанию |
|-----|---------------------|
| `claude` | `claude-sonnet-4-6` |
| `openai` | `gpt-4o` |
| `deepseek` | `deepseek-chat` |
| `gemini` | `gemini-2.0-flash` |
| `mistral` | `mistral-large-latest` |

### Автоматический выбор (auto)

Если `<provider>` отсутствует или `<name>` не указан -- сервис выбирает провайдера и модель автоматически.

Варианты:
- Только `<name>` -- сервис выбирает модель по умолчанию для провайдера.
- Только `<model>` -- сервис определяет провайдера по имени модели (claude* -> Claude, gpt*/o1*/o3*/o4* -> OpenAI, deepseek* -> DeepSeek, gemini* -> Gemini, mistral*/codestral* -> Mistral).

### Fallback

При ошибке основного провайдера (5xx, таймаут, rate limit) сервис автоматически повторяет запрос к fallback. Вложенные `<fallback>` создают цепочку.

---

## 7. Секция `<prompt>`

Содержит упорядоченную последовательность блоков `<block>`. Порядок блоков -- это контракт. Сервис передает блоки в LLM строго в порядке их появления в XML.

### 7.1. Атрибуты `<block>`

| Атрибут | Обязательный | Тип | Описание |
|---------|:------------:|-----|----------|
| `type` | нет | string | Тип блока. По умолчанию `data` |
| `role` | нет | enum | `system`, `user` (по умолчанию), `assistant`, `tool` |
| `id` | нет | string | Уникальный ID в пределах prompt. Формат: `[a-zA-Z_][a-zA-Z0-9_-]*` |
| `label` | нет | string | Человекочитаемая подпись блока |
| `format` | нет | enum | `text` (по умолчанию), `csv`, `json`, `xml`, `markdown`, `base64` |
| `media_type` | условно | string | MIME-тип. **Обязателен при `format="base64"`** |
| `for` | нет | string | ID блока `data`, к которому относится данный `description` |
| `tool_call_id` | условно | string | **Обязателен для `type="history_tool_result"`** |
| `cache` | нет | boolean | `true`/`false`. Подсказка использовать prompt caching. По умолчанию `false` |

### 7.2. Типы блоков и допустимые роли

| Тип | Назначение | Допустимые роли |
|-----|-----------|----------------|
| `system` | Роль модели, поведение | `system` |
| `instruction` | Основная задача, вопрос | `user` |
| `description` | Пояснение к данным, контекст | `user` |
| `data` | Структурированные данные | `user` |
| `example` | Few-shot пример | `user`, `assistant` |
| `constraint` | Ограничения, правила | `system`, `user` |
| `output_format` | Требования к формату ответа | `system`, `user` |
| `image` | Изображение (base64) | `user` |
| `document` | PDF/документ (base64) | `user` |
| `audio` | Аудио (base64) | `user` |
| `url` | URL для скачивания контента | `user` |
| `history` | Turn из истории диалога | `user`, `assistant` |
| `history_tool_result` | Результат вызова инструмента из истории | `tool` |
| `prefix` | Начало ответа (assistant prefill) | `assistant` |

**Любая другая комбинация type + role -- ошибка `INVALID_TYPE_ROLE_COMBINATION`.**

### 7.3. Правила для `format="base64"` (медиа-блоки)

- **Обязательно** указать `media_type`.
- Поддерживаемые MIME-типы:
  - Изображения: `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`
  - Документы: `application/pdf`
  - Аудио: `audio/mp3`, `audio/wav`, `audio/ogg`, `audio/flac`, `audio/webm`
  - Видео: `video/mp4`, `video/webm` (только Gemini)
- Максимальный размер одного медиа-блока: 20 МБ (base64-кодированный).

### 7.4. Связь description -> data

**Явная привязка (рекомендуется):**

```xml
<block type="description" role="user" for="candles">Описание данных...</block>
<block type="data" role="user" id="candles" format="csv">datetime,open,high,low,close...</block>
```

**Позиционная привязка:** Если `for` не указан, `description` привязывается к следующему за ним блоку `data`. Если следующий блок -- не `data`, это ошибка `ORPHAN_DESCRIPTION`.

### 7.5. Форматирование блоков `data`

При сборке промта для LLM блок `data` оборачивается:

```
<{id} label="{label}">
{content}
</{id}>
```

Если `id` не указан, автоматически генерируется: `data_1`, `data_2` и т.д.

### 7.6. Блок `prefix` (assistant prefill)

Допускается максимум **один** блок `prefix`. Помещается как последнее assistant-сообщение перед генерацией. LLM продолжит ответ с этого текста. Нативно поддерживается Claude; для других провайдеров эмулируется.

### 7.7. Блоки истории (multi-turn диалог)

Клиент управляет историей самостоятельно. Каждый блок `history` -- один turn.

**Правила:**
- Первый `history` блок должен иметь `role="user"`.
- Блоки должны чередоваться: `user` -> `assistant` -> `user` -> ...
- Блоки истории должны идти подряд, перед блоками текущего запроса.
- Ответ assistant с tool_calls передается как `history` с `format="json"`.
- За ним обязательно следует `history_tool_result` с соответствующим `tool_call_id`.
- Контролируйте длину истории -- рекомендуется не более 70% контекстного окна модели.

**Пример истории с tool_use:**

```xml
<!-- Ход 1: вопрос пользователя -->
<block type="history" role="user">Проанализируй EUR/USD.</block>

<!-- Ход 1: ответ LLM с вызовом инструмента -->
<block type="history" role="assistant" format="json"><![CDATA[
  {
    "content": "Мне нужно получить текущую цену.",
    "tool_calls": [
      {"id": "call_001", "name": "get_price", "arguments": {"symbol": "EURUSD"}}
    ]
  }
]]></block>

<!-- Результат вызова инструмента -->
<block type="history_tool_result" role="tool" tool_call_id="call_001"><![CDATA[
  {"price": 1.1248, "timestamp": "2026-03-18T10:00:00Z"}
]]></block>

<!-- Ход 1: финальный ответ LLM -->
<block type="history" role="assistant">EUR/USD в зоне перекупленности...</block>

<!-- Текущий ход: новый вопрос -->
<block type="instruction" role="user">Теперь проанализируй GBP/USD.</block>
```

---

## 8. Секция `<tools>`

Описание инструментов для function calling.

```xml
<tools tool_choice="auto">
  <tool>
    <name>get_price</name>
    <description>Получить текущую цену инструмента</description>
    <params>
      <param name="symbol" type="string" required="true">
        <description>Тикер, например EURUSD</description>
      </param>
      <param name="timeframe" type="string" required="false" enum='["M1","H1","D1"]'>
        <description>Таймфрейм</description>
      </param>
    </params>
  </tool>
</tools>
```

### Атрибут `tool_choice`

| Значение | Описание |
|----------|----------|
| `auto` | LLM решает сам (по умолчанию) |
| `none` | Запретить вызов инструментов |
| `required` | Обязать вызвать хотя бы один |

### Атрибуты `<param>`

| Атрибут | Обязательный | Описание |
|---------|:------------:|----------|
| `name` | да | Имя параметра |
| `type` | нет | `string`, `number`, `integer`, `boolean`, `array`, `object` |
| `required` | нет | `true`/`false`. По умолчанию `false` |
| `enum` | нет | JSON-массив допустимых значений |
| `default` | нет | Значение по умолчанию |

Для сложных типов поддерживаются вложенные `<params>`, `<items>`, `<properties>`.

---

## 9. Секция `<parameters>`

```xml
<parameters>
  <temperature>0.0</temperature>
  <max_tokens>2048</max_tokens>
  <top_p>0.9</top_p>
  <top_k>40</top_k>
  <stop_sequences>["\n\n---"]</stop_sequences>
  <stream>false</stream>
  <response_format>
    <type>json_schema</type>
    <name>trade_recommendation</name>
    <strict>true</strict>
    <schema><![CDATA[
      {
        "type": "object",
        "properties": {
          "action": {"type": "string", "enum": ["LONG", "SHORT"]},
          "confidence": {"type": "number"}
        },
        "required": ["action", "confidence"],
        "additionalProperties": false
      }
    ]]></schema>
  </response_format>
  <reasoning>
    <enabled>true</enabled>
    <effort>medium</effort>
    <max_tokens>4096</max_tokens>
  </reasoning>
  <extra>
    <param name="presence_penalty">0.6</param>
    <param name="seed">42</param>
  </extra>
</parameters>
```

### Стандартные параметры

| Параметр | Тип | Диапазон | По умолчанию | Описание |
|----------|-----|----------|:------------:|----------|
| `temperature` | float | 0.0 -- 2.0 | дефолт провайдера | Уровень случайности |
| `max_tokens` | integer | > 0 | дефолт провайдера (Claude: 4096) | Максимум токенов в ответе |
| `top_p` | float | 0.0 -- 1.0 | дефолт провайдера | Nucleus sampling |
| `top_k` | integer | > 0 | -- | Top-k sampling. Игнорируется при отсутствии поддержки |
| `stop_sequences` | json array | максимум 4 элемента | -- | Строки-стопы |
| `stream` | boolean | -- | `false` | Включить стриминг |

### Structured Output (response_format)

| Тип | Описание |
|-----|----------|
| `text` | Свободный формат (по умолчанию) |
| `json_object` | Провайдер гарантирует валидный JSON |
| `json_schema` | Провайдер гарантирует JSON по указанной схеме |

Для `json_schema` обязательны:
- `<name>` -- идентификатор (pattern: `^[a-zA-Z_][a-zA-Z0-9_-]*$`, максимум 64 символа)
- `<schema>` -- JSON Schema с обязательным полем `type`
- `<strict>` -- `true`/`false` (необязательно)

#### Поддержка structured output по провайдерам

| Провайдер | json_object | json_schema | Стратегия |
|-----------|:-----------:|:-----------:|-----------|
| OpenAI | нативно | нативно | Нативная передача |
| Claude | нативно | нативно | Нативная передача |
| Gemini | нативно | нативно | Нативная передача |
| Mistral | нативно | нативно | Нативная передача |
| DeepSeek | нативно | **эмуляция** | json_object + schema в system prompt |

При эмуляции в callback-ответе `structured_output_fallback = true`. **Клиент должен самостоятельно валидировать ответ на соответствие схеме.**

#### Совместимость JSON Schema между провайдерами

Сервис передает JSON Schema провайдеру **как есть**. Если schema содержит неподдерживаемые ключевые слова, провайдер отклонит запрос.

**Минимальный безопасный набор (все провайдеры):** `type`, `properties`, `required`, `items`, `enum`, `description`, `anyOf`.

**Ключевые ограничения:**
- **Claude** не поддерживает: `minimum`/`maximum`, `minItems`/`maxItems`, `minLength`/`maxLength`, `pattern`, `format`, `default`, `const`.
- **Gemini** не поддерживает: `additionalProperties`, `const`.

Для целочисленных диапазонов вместо `minimum`/`maximum` используйте `enum` (до ~50 значений). Для непрерывных чисел -- описывайте ограничения в `description` поля и выполняйте post-validation.

### Расширенное мышление (reasoning)

```xml
<reasoning>
  <enabled>true</enabled>
  <effort>medium</effort>       <!-- low, medium, high -->
  <max_tokens>4096</max_tokens>
</reasoning>
```

- Claude: маппится в extended thinking. При включении temperature автоматически устанавливается в 1.0.
- OpenAI: маппится в reasoning_effort для моделей o-серии.
- Если провайдер/модель не поддерживает -- параметр игнорируется.

### Дополнительные параметры (`<extra>`)

Передаются провайдеру без обработки. Примеры:

| Параметр | Провайдеры | Описание |
|----------|-----------|----------|
| `presence_penalty` | OpenAI, DeepSeek | Штраф за повторение тем (-2.0 .. 2.0) |
| `frequency_penalty` | OpenAI, DeepSeek | Штраф за частоту повторения (-2.0 .. 2.0) |
| `logprobs` | OpenAI | Возвращать log-вероятности (true/false) |
| `seed` | OpenAI | Seed для детерминистичности |
| `service_tier` | OpenAI | `auto`, `default`, `flex` |

Неизвестные провайдеру параметры будут проигнорированы провайдером.

### Стриминг

При `<stream>true</stream>` сервис транслирует токены по мере получения через callback URL. Формат -- Server-Sent Events (SSE):

```
event: token
data: {"request_id":"req_001","content":"Привет","index":0}

event: token
data: {"request_id":"req_001","content":" мир","index":1}

event: done
data: {"request_id":"req_001","finish_reason":"end_turn","usage":{"input_tokens":100,"output_tokens":50}}
```

---

## 10. Секция `<callback>` (обязательна)

```xml
<callback>
  <url>https://your-api.example.com/api/llm-response</url>
  <method>POST</method>
  <headers>
    <header name="X-Custom-Header">value</header>
  </headers>
  <timeout>120</timeout>
  <retry>
    <max_attempts>3</max_attempts>
    <backoff>exponential</backoff>
    <initial_delay>1</initial_delay>
  </retry>
</callback>
```

### Элементы

| Элемент | Обязательный | По умолчанию | Описание |
|---------|:------------:|:------------:|----------|
| `<url>` | да | -- | HTTPS URL. Должен быть в whitelist клиента |
| `<method>` | нет | `POST` | `POST` или `PUT` |
| `<headers>` | нет | -- | Дополнительные заголовки для callback |
| `<timeout>` | нет | `300` | Максимальное время ожидания ответа от LLM (секунды) |
| `<retry>` | нет | см. ниже | Настройки повторных попыток доставки |

### Настройки retry

| Элемент | По умолчанию | Описание |
|---------|:------------:|----------|
| `<max_attempts>` | `3` | 1 -- 5 (включая первую попытку) |
| `<backoff>` | `exponential` | `exponential` или `fixed` |
| `<initial_delay>` | `1` | Начальная задержка в секундах |

При exponential backoff: 1с, 2с, 4с, 8с...

### Требования к callback endpoint клиента

1. **Протокол:** HTTPS (TLS 1.2+). В dev/docker-окружении допускается HTTP.
2. **Принимать** `Content-Type: application/json; charset=utf-8`.
3. **Верифицировать подпись** (см. раздел 14).
4. **Отвечать HTTP 200/202** при успешном получении в течение **10 секунд**.
5. **Быть идемпотентным** -- повторный callback с тем же `request_id` не должен дублировать обработку.
6. При ответе **4xx** -- сервис не повторяет доставку. При **5xx или таймауте** -- повторяет по настройкам retry.

---

## 11. Синхронный ответ (подтверждение приема)

### Успех -- HTTP 202 Accepted

```json
{
  "status": "accepted",
  "request_id": "req_001",
  "meta": {
    "request_id": "req_001",
    "session_id": "sess_abc",
    "step_id": 1,
    "source": "my_bot",
    "custom_field": "value"
  },
  "provider": {
    "name": "claude",
    "model": "claude-sonnet-4-6"
  },
  "callback_url": "https://your-api.example.com/api/llm-response",
  "dev_mode": false
}
```

Если провайдер или модель не указаны клиентом, в `provider` будет `"auto"`.

### Ошибка -- HTTP 4xx/5xx

```json
{
  "status": "error",
  "error": {
    "code": "ERROR_CODE",
    "message": "Описание ошибки.",
    "details": {}
  }
}
```

### Полный перечень кодов ошибок

| Код | HTTP | Описание |
|-----|:----:|----------|
| `UNAUTHORIZED` | 401 | Отсутствует или невалидный заголовок `Authorization` |
| `FORBIDDEN` | 403 | API-ключ невалиден, отозван или неактивен |
| `PROVIDER_NOT_ALLOWED` | 403 | Клиент не имеет доступа к указанному провайдеру |
| `CALLBACK_URL_NOT_ALLOWED` | 403 | Callback URL не в whitelist клиента |
| `INVALID_CONTENT_TYPE` | 415 | Content-Type не `application/xml` |
| `PAYLOAD_TOO_LARGE` | 413 | Размер тела превышает 50 МБ |
| `RATE_LIMIT_EXCEEDED` | 429 | Превышен лимит запросов. Заголовки: `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` |
| `INVALID_XML` | 400 | XML-документ невалиден |
| `VERSION_NOT_SUPPORTED` | 400 | Версия != 3.0 |
| `MISSING_REQUEST_ID` | 400 | Отсутствует `<request_id>` в `<meta>` |
| `MISSING_STEP_ID` | 400 | `session_id` указан без `step_id` |
| `MISSING_USER_BLOCK` | 400 | Нет блока с `role="user"` |
| `MISSING_CALLBACK_URL` | 400 | Отсутствует `<url>` в `<callback>` |
| `INSECURE_CALLBACK_URL` | 400 | Callback URL использует `http://` (в production) |
| `PROVIDER_UNKNOWN` | 400 | Провайдер не поддерживается |
| `UNKNOWN_BLOCK_TYPE` | 400 | Неизвестный `type` блока |
| `UNKNOWN_FORMAT` | 400 | Неизвестный `format` блока |
| `INVALID_TYPE_ROLE_COMBINATION` | 400 | Недопустимая комбинация type + role |
| `MISSING_MEDIA_TYPE` | 400 | `format="base64"` без `media_type` |
| `MISSING_TOOL_CALL_ID` | 400 | `history_tool_result` без `tool_call_id` |
| `DUPLICATE_BLOCK_ID` | 400 | Повторяющийся `id` блока |
| `DANGLING_DESCRIPTION` | 400 | `for` ссылается на несуществующий `id` |
| `ORPHAN_DESCRIPTION` | 400 | `description` без `for`, за которым нет `data` |
| `HISTORY_ORDER_VIOLATION` | 400 | Нарушен порядок history-блоков (первый не user или нет чередования) |
| `INVALID_PARAMETER` | 400 | Некорректное значение параметра (temperature, max_tokens, top_p, stop_sequences, retry.max_attempts, response_format, callback method и т.д.) |
| `INTERNAL_ERROR` | 500 | Внутренняя ошибка сервиса |

---

## 12. Callback-ответ (асинхронный)

### Заголовки

```
Content-Type: application/json; charset=utf-8
X-LLM-Signature: sha256=<HMAC-SHA256 hex>
X-LLM-Timestamp: <unix timestamp>
X-LLM-Nonce: <uuid v4>
X-LLM-Request-Id: req_001
X-LLM-Event-Type: completion | error | stream_token | stream_done | stream_error
```

Плюс пользовательские заголовки из `<headers>` в секции callback.

### Успешный ответ (X-LLM-Event-Type: completion)

```json
{
  "status": "ok",
  "meta": {
    "request_id": "req_001",
    "session_id": "sess_abc",
    "step_id": 1,
    "custom_field": "value"
  },
  "provider": {
    "name": "claude",
    "model": "claude-sonnet-4-6",
    "is_fallback": false
  },
  "result": {
    "content": "Текст ответа LLM...",
    "tool_calls": [],
    "finish_reason": "end_turn",
    "usage": {
      "input_tokens": 1523,
      "output_tokens": 487,
      "cache_creation_input_tokens": 0,
      "cache_read_input_tokens": 0
    },
    "reasoning": null
  },
  "structured_output_fallback": false,
  "latency_ms": 3200
}
```

### Ответ с вызовом инструмента

```json
{
  "status": "ok",
  "meta": {"request_id": "req_001"},
  "provider": {"name": "claude", "model": "claude-sonnet-4-6", "is_fallback": false},
  "result": {
    "content": "Мне нужно получить текущую цену.",
    "tool_calls": [
      {
        "id": "call_001",
        "name": "get_price",
        "arguments": {"symbol": "EURUSD"}
      }
    ],
    "finish_reason": "tool_use",
    "usage": {
      "input_tokens": 1523,
      "output_tokens": 62,
      "cache_creation_input_tokens": 0,
      "cache_read_input_tokens": 0
    },
    "reasoning": null
  },
  "structured_output_fallback": false,
  "latency_ms": 1200
}
```

**После получения `tool_calls` клиент должен:**
1. Выполнить запрошенную функцию с полученными `arguments`.
2. Сформировать новый запрос (step_id++) с полной историей, включая ответ с tool_calls как `history` (format="json") и результат как `history_tool_result`.

### Ответ с reasoning

```json
{
  "result": {
    "content": "Рекомендация: ...",
    "reasoning": {
      "content": "<содержимое мышления>",
      "tokens": 2048
    }
  }
}
```

### Ответ с ошибкой (X-LLM-Event-Type: error)

```json
{
  "status": "error",
  "meta": {"request_id": "req_001"},
  "error": {
    "code": "PROVIDER_UNAVAILABLE",
    "message": "Provider 'claude' returned HTTP 503.",
    "details": {}
  },
  "latency_ms": 15000
}
```

### Описание полей callback-ответа

| Поле | Тип | Описание |
|------|-----|----------|
| `status` | string | `"ok"` или `"error"` |
| `meta` | object | Все поля из `<meta>` запроса без изменений |
| `provider.name` | string | Фактический провайдер |
| `provider.model` | string | Фактическая модель |
| `provider.is_fallback` | boolean | `true`, если использован fallback |
| `result.content` | string/null | Текст ответа LLM. Может быть `null` при `tool_use` |
| `result.tool_calls` | array | Вызовы инструментов. Пустой `[]`, если нет |
| `result.tool_calls[].id` | string | ID вызова |
| `result.tool_calls[].name` | string | Имя инструмента |
| `result.tool_calls[].arguments` | object | Аргументы |
| `result.finish_reason` | string | `end_turn`, `max_tokens`, `stop_sequence`, `tool_use`, `content_filter` |
| `result.usage.input_tokens` | integer | Входные токены |
| `result.usage.output_tokens` | integer | Выходные токены |
| `result.usage.cache_creation_input_tokens` | integer | Токены, записанные в кеш |
| `result.usage.cache_read_input_tokens` | integer | Токены, прочитанные из кеша |
| `result.reasoning` | object/null | `{content, tokens}` если reasoning включен |
| `structured_output_fallback` | boolean | `true` если structured output был эмулирован |
| `latency_ms` | integer | Время обработки (мс) |
| `error.code` | string | Код ошибки (при `status: "error"`) |
| `error.message` | string | Описание ошибки |
| `error.details` | object | Дополнительные данные |

### Коды ошибок callback-ответа

| Код | Описание |
|-----|----------|
| `PROVIDER_UNAVAILABLE` | Провайдер (и все fallback) вернули ошибку |
| `PROVIDER_TIMEOUT` | Провайдер не ответил за `<timeout>` секунд |
| `PROVIDER_RATE_LIMITED` | Провайдер вернул 429, retry и fallback исчерпаны |
| `PROVIDER_CONTENT_FILTERED` | Контент отклонен модерацией провайдера |
| `PROVIDER_CONTEXT_LENGTH` | Запрос превысил контекстное окно модели |
| `PROVIDER_INVALID_REQUEST` | Провайдер вернул ошибку валидации |
| `PROVIDER_INSUFFICIENT_FUNDS` | Недостаточно средств на аккаунте провайдера |
| `STREAM_INTERRUPTED` | Стриминг прерван |
| `CALLBACK_DELIVERY_FAILED` | Не удалось доставить ответ (все retry исчерпаны) |
| `INTERNAL_ERROR` | Внутренняя ошибка сервиса |

---

## 13. Endpoint получения raw-ответов провайдера

Для отладки доступен endpoint получения сырых ответов провайдеров.

| Параметр | Значение |
|----------|----------|
| Метод | `GET` |
| URL | `/api/v1/llm/requests/{requestId}/raw-responses` |
| Middleware | `auth.api_key`, `rate.api_key` |

### Ограничения

- `requestId`: максимум 256 символов, допустимые символы: `a-zA-Z0-9_-:.`
- Клиент видит только **свои** raw-ответы (tenant isolation). Чужой `requestId` возвращает 404.
- Заголовки провайдера фильтруются: удаляются `authorization`, `x-api-key`, `api-key` (case-insensitive).

### Ответ -- 200 OK

```json
{
  "status": "ok",
  "request_id": "req_001",
  "data": [
    {
      "id": 1,
      "provider": "claude",
      "model": "claude-sonnet-4-6",
      "http_status": 200,
      "response_body": { ... },
      "response_headers": { ... },
      "is_fallback_attempt": false,
      "duration_ms": 1230,
      "created_at": "2026-03-22T10:15:30.000000Z"
    }
  ]
}
```

Один `request_id` может иметь несколько raw-ответов (основная попытка + fallback). Записи возвращаются в хронологическом порядке.

### Ошибки

| HTTP | Код | Описание |
|:----:|-----|----------|
| 401 | `UNAUTHORIZED` | Нет/невалидный API key |
| 403 | `FORBIDDEN` | Отозванный API key |
| 404 | `REQUEST_NOT_FOUND` | Запрос не найден / чужой / невалидный формат ID |
| 429 | `RATE_LIMIT_EXCEEDED` | Превышен rate limit |

---

## 14. Верификация подписи callback

Каждый callback-запрос подписан HMAC-SHA256.

### Алгоритм (на стороне клиента)

1. Извлечь `X-LLM-Timestamp`, `X-LLM-Nonce`, `X-LLM-Signature` из заголовков.
2. Проверить что `X-LLM-Timestamp` не старше **300 секунд** (защита от replay-атак).
3. Сформировать строку: `{timestamp}.{nonce}.{raw_body}`.
4. Вычислить: `hash_hmac('sha256', string, signing_secret)`.
5. Сравнить `sha256={hmac}` с `X-LLM-Signature` (timing-safe сравнение).
6. Отклонять повторные `X-LLM-Nonce` (защита от повторной доставки).

`signing_secret` -- секрет, полученный при создании клиента.

### Пример на PHP

```php
function verifySignature(Request $request, string $signingSecret): bool
{
    $timestamp = $request->header('X-LLM-Timestamp');
    $nonce = $request->header('X-LLM-Nonce');
    $signature = $request->header('X-LLM-Signature');
    
    // Проверка replay
    if (abs(time() - (int)$timestamp) > 300) {
        return false;
    }
    
    $payload = "{$timestamp}.{$nonce}.{$request->getContent()}";
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $signingSecret);
    
    return hash_equals($expected, $signature);
}
```

---

## 15. Rate limiting

- Лимит настраивается per API key (по умолчанию 60 req/min, sliding window).
- Заголовки в каждом ответе:

| Заголовок | Описание |
|-----------|----------|
| `X-RateLimit-Limit` | Максимум запросов в окне |
| `X-RateLimit-Remaining` | Оставшиеся запросы |
| `X-RateLimit-Reset` | Unix timestamp сброса окна |

При превышении -- HTTP 429 с дополнительным заголовком `Retry-After` (секунды).

---

## 16. Идемпотентность

При передаче заголовка `X-Idempotency-Key` повторный запрос с тем же ключом (от того же клиента) в течение **24 часов** вернет кешированный ответ без повторной обработки.

- Ключ: строка до 256 символов.
- Проверяется сначала в кеше (Redis), затем в БД.
- Уникальность ключа -- в рамках одного клиента.

---

## 17. Очереди и приоритеты

Запросы обрабатываются в 3 очередях:

| Приоритет | Очередь | Описание |
|-----------|---------|----------|
| `high` | high | Обрабатывается первой |
| `normal` | default | По умолчанию |
| `low` | low | Обрабатывается последней |

Worker обрабатывает очереди в порядке: `high` -> `default` -> `low`.

- Timeout обработки одного запроса: 600 секунд.
- Ошибки не повторяются -- при сбое отправляется error callback.
- Данные pending_prompts и pending_responses хранятся **3 дня**, затем удаляются.

---

## 18. Поведение при ошибках провайдера

### Fallback

Если основной провайдер вернул ошибку (5xx, timeout, rate limit), сервис автоматически пробует fallback-провайдера из `<fallback>` цепочки.

### Пауза провайдера

При получении HTTP 429 (rate limit) или HTTP 402 (insufficient funds) от провайдера, сервис **ставит провайдера на паузу**:
- Rate limit (429 с Retry-After): временная пауза с автовосстановлением.
- Insufficient funds (402): постоянная пауза. Требуется ручное возобновление: `llm:resume-provider`.
- Запросы к приостановленному провайдеру **не теряются** -- остаются в очереди и ожидают возобновления.

---

## 19. Полный пример

### Первый запрос

```xml
<?xml version="1.0" encoding="UTF-8"?>
<llm_request version="3.0">
  <meta>
    <request_id>req_20260318_001</request_id>
    <session_id>sess_001</session_id>
    <step_id>1</step_id>
    <source>trading_bot</source>
    <priority>normal</priority>
  </meta>

  <provider>
    <name>claude</name>
    <fallback>
      <name>openai</name>
    </fallback>
  </provider>

  <prompt>
    <block type="system" role="system">
      Ты опытный аналитик финансовых рынков.
    </block>

    <block type="instruction" role="user">
      Проанализируй EUR/USD на основе технических индикаторов.
    </block>

    <block type="description" role="user" for="indicators">
      Значения индикаторов на закрытии последней H1-свечи.
    </block>

    <block type="data" role="user" id="indicators" label="Tech indicators" format="json">
      {"RSI": 74.3, "MACD": 0.0023, "MACD_signal": 0.0019}
    </block>

    <block type="constraint" role="user">
      Не давай рекомендации с плечом выше 1:5.
    </block>
  </prompt>

  <tools tool_choice="auto">
    <tool>
      <name>get_price</name>
      <description>Получить текущую цену инструмента</description>
      <params>
        <param name="symbol" type="string" required="true">
          <description>Тикер, например EURUSD</description>
        </param>
      </params>
    </tool>
  </tools>

  <parameters>
    <temperature>0.0</temperature>
    <max_tokens>2048</max_tokens>
  </parameters>

  <callback>
    <url>https://your-api.example.com/api/llm-response</url>
    <timeout>120</timeout>
  </callback>
</llm_request>
```

### curl-запрос

```bash
curl -X POST http://llm-gateway.example.com/api/v1/llm/request \
  -H "Content-Type: application/xml; charset=utf-8" \
  -H "Authorization: Bearer <api_key>" \
  -H "X-Idempotency-Key: idem_20260318_001" \
  -d @request.xml
```

---

## 20. Важные ограничения

| Ограничение | Значение |
|-------------|----------|
| Максимальный размер тела запроса | 50 МБ |
| Максимальный размер медиа-блока | 20 МБ (base64) |
| Максимум stop_sequences | 4 |
| Максимум retry attempts (callback) | 5 |
| Максимум блоков prefix | 1 |
| TTL idempotency key | 24 часа |
| TTL pending данных | 3 дня |
| Таймаут ответа callback endpoint | 10 секунд |
| Версия протокола | только 3.0 |
| Callback URL | только HTTPS (кроме dev/docker) |
| requestId для raw-responses | макс. 256 символов, `[a-zA-Z0-9_\-:.]` |
