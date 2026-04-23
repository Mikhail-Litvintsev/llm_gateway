# Спецификация: Endpoint получения сырых ответов провайдера

## Обзор

Клиентский endpoint для получения сырых (raw) ответов от LLM-провайдеров по `request_id`. Позволяет клиенту запросить оригинальный ответ провайдера для целей отладки и аудита.

---

## Endpoint

| Параметр | Значение |
|----------|----------|
| Метод | `GET` |
| URL | `/api/v1/llm/requests/{requestId}/raw-responses` |
| Middleware | `auth.api_key`, `rate.api_key` |
| Content-Type ответа | `application/json` |

### Параметр пути

| Имя | Тип | Обязательный | Описание |
|-----|-----|--------------|----------|
| `requestId` | string | да | Идентификатор запроса (`request_id` из `request_log`) |

---

## Аутентификация и авторизация

1. **Аутентификация** — стандартная через `Authorization: Bearer {api_key}` (middleware `auth.api_key`).
2. **Rate limiting** — стандартный per API key (middleware `rate.api_key`).
3. **Изоляция данных (tenant isolation)** — клиент может получить **только** raw-ответы, принадлежащие его собственным запросам. Проверка: `request_log.api_client_id = authenticated_client.id`. Если `request_id` существует, но принадлежит другому клиенту, возвращается `404` (не `403`), чтобы не раскрывать факт существования чужих запросов.

---

## Логика обработки

### Алгоритм

1. Аутентифицировать клиента (middleware).
2. Найти запись в `request_log` по `request_id` **и** `api_client_id` текущего клиента.
3. Если запись не найдена — вернуть `404`.
4. Получить все связанные записи из `raw_responses` по `request_log_id`.
5. Если записей нет (запрос найден, но raw-ответы отсутствуют) — вернуть `200` с пустым массивом.
6. Вернуть `200` с массивом raw-ответов.

### Область данных

Один `request_id` может иметь несколько raw-ответов (основная попытка + fallback-попытки). Все записи возвращаются в хронологическом порядке (`created_at ASC`).

---

## Формат ответа

### Успешный ответ — 200 OK

```json
{
    "status": "ok",
    "request_id": "abc-123",
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
        },
        {
            "id": 2,
            "provider": "openai",
            "model": "gpt-4o",
            "http_status": 200,
            "response_body": { ... },
            "response_headers": null,
            "is_fallback_attempt": true,
            "duration_ms": 980,
            "created_at": "2026-03-22T10:15:31.000000Z"
        }
    ]
}
```

### Успешный ответ без данных — 200 OK

```json
{
    "status": "ok",
    "request_id": "abc-123",
    "data": []
}
```

### Ошибки

#### 401 Unauthorized — отсутствует или невалидный API key

```json
{
    "status": "error",
    "error": {
        "code": "UNAUTHORIZED",
        "message": "Missing or invalid Authorization header."
    }
}
```

#### 403 Forbidden — отозванный API key

```json
{
    "status": "error",
    "error": {
        "code": "FORBIDDEN",
        "message": "API key is revoked or inactive."
    }
}
```

#### 404 Not Found — запрос не найден или принадлежит другому клиенту

```json
{
    "status": "error",
    "error": {
        "code": "REQUEST_NOT_FOUND",
        "message": "Request not found."
    }
}
```

#### 429 Too Many Requests — превышен rate limit

```json
{
    "status": "error",
    "error": {
        "code": "RATE_LIMIT_EXCEEDED",
        "message": "Rate limit exceeded."
    }
}
```

Заголовки: `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.

---

## TTL и доступность данных

Записи в `raw_responses` каскадно удаляются при удалении связанной записи `request_log`. Отдельного TTL для `raw_responses` нет, но `request_log` может очищаться по политике retention. Клиент должен быть осведомлен, что данные могут быть недоступны для старых запросов.

---

## Безопасность

| Аспект | Реализация |
|--------|------------|
| Аутентификация | Bearer token, SHA-256 hash lookup |
| Авторизация | Tenant isolation — фильтр по `api_client_id` |
| Информационная утечка | 404 вместо 403 для чужих запросов |
| Rate limiting | Стандартный per API key |
| Валидация входа | `requestId` — строка, максимум 256 символов, regex `^[a-zA-Z0-9_\-:.]+$` |
| SQL injection | Eloquent ORM (параметризованные запросы) |
| Sensitive headers | Поле `response_headers` может содержать заголовки провайдера — при необходимости фильтровать чувствительные (Authorization, api-key) перед отдачей клиенту |

### Фильтрация заголовков ответа провайдера

Из `response_headers` перед возвратом клиенту **удаляются** заголовки, которые могут содержать credentials шлюза:

- `authorization`
- `x-api-key`
- `api-key`

Сравнение имён заголовков — case-insensitive.

---

## Валидация requestId

Параметр `requestId` из URL должен проходить валидацию:

- Максимальная длина: 256 символов.
- Допустимые символы: `a-z`, `A-Z`, `0-9`, `_`, `-`, `:`, `.`.
- При невалидном формате — возвращать `404` (не `400`), чтобы не раскрывать внутреннюю логику валидации.

---

## Маршрутизация

Добавить в `routes/api.php`:

```php
Route::get('/v1/llm/requests/{requestId}/raw-responses', [RawResponseController::class, 'show'])
    ->middleware(['auth.api_key', 'rate.api_key'])
    ->where('requestId', '[a-zA-Z0-9_\-:.]+');
```

---

## Контроллер

Файл: `app/Http/Controllers/Api/V1/RawResponseController.php`

Контроллер должен следовать паттерну `LlmRequestController`:
- Получать `api_client` из `$request->attributes->get('api_client')`.
- Использовать приватный метод `errorResponse()` для формирования ошибок.
- Делегировать бизнес-логику компоненту.

---

## Компонент

Бизнес-логика размещается в существующем компоненте `ProviderGateway` или как самостоятельный query-класс, так как операция — чтение, без сложной оркестрации.

Рекомендуемый подход — query-метод непосредственно в контроллере через Eloquent, т.к. логика тривиальна (lookup + tenant filter + eager load). Выносить в отдельный компонент не требуется.

---

## Тестирование

### Unit-тесты

- Валидация `requestId` формата.
- Фильтрация чувствительных заголовков.

### Feature-тесты

| Сценарий | Ожидаемый результат |
|----------|---------------------|
| Запрос без `Authorization` header | 401 |
| Запрос с невалидным API key | 403 |
| Запрос с валидным key, несуществующий `requestId` | 404 |
| Запрос с валидным key, чужой `requestId` | 404 |
| Запрос с валидным key, свой `requestId`, нет raw-ответов | 200, `data: []` |
| Запрос с валидным key, свой `requestId`, есть raw-ответы | 200, массив raw-ответов |
| Запрос с fallback-попытками | 200, несколько записей, `is_fallback_attempt` корректен |
| Невалидный формат `requestId` (спецсимволы) | 404 |
| Превышен rate limit | 429 |
| Фильтрация sensitive headers в `response_headers` | Заголовки `authorization`, `x-api-key`, `api-key` отсутствуют в ответе |
