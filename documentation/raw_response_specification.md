# Хранение сырых данных запросов и ответов

## Назначение

Шлюз сохраняет сырые тела запросов и ответов для audit, debug, replay и compliance.
Данные распределены по трём таблицам: `requests` (структурированные метаданные),
`request_usage` (детальный расход токенов и стоимость), `request_raw` (полные тела
запроса и ответа в JSON).

---

## Схема `requests`

Структурированный лог каждого запроса.

| Колонка | Тип | Описание |
|---------|-----|----------|
| `request_id` | `char(28)` PK | Уникальный ID запроса (`req_...`) |
| `client_id` | `bigint unsigned` FK → `clients.id` | Клиент-владелец |
| `endpoint` | `enum` | Тип: `messages`, `batch_item`, `count_tokens`, `session_message` |
| `mode` | `enum` | Режим: `sync`, `sync_stream`, `async_callback`, `batch` |
| `model_alias` | `varchar` | Запрошенный алиас (`claude-sonnet`) |
| `model_snapshot` | `varchar` | Реальный snapshot (`claude-sonnet-4-6`) |
| `anthropic_request_id` | `varchar` nullable | ID запроса на стороне Anthropic |
| `anthropic_organization_id` | `varchar` nullable | Organization ID из ответа Anthropic |
| `status` | `varchar(32)` | Значения из `App\Components\Logging\Enums\RequestStatus`: `accepted`, `in_progress`, `completed`, `completed_disconnected`, `failed_client_error`, `failed_server_error`, `failed_callback_delivery`, `failed_validation`, `failed_auth` |
| `http_status` | `smallint unsigned` nullable | HTTP-код от Anthropic (200, 400, 429, ...) |
| `error_type` | `varchar` nullable | Тип ошибки (`overloaded_error`, `rate_limit_error`, ...) |
| `error_message` | `text` nullable | Текст ошибки |
| `service_tier_used` | `varchar` nullable | Использованный tier (`standard`, `auto`) |
| `created_at` | `datetime` | Время приёма запроса |
| `started_at` | `datetime` nullable | Время начала обработки Anthropic |
| `completed_at` | `datetime` nullable | Время получения ответа |

Индексы: `(client_id, created_at)`, `(status)`.

---

## Схема `request_usage`

Детальный расход токенов и стоимость. Связан 1:1 с `requests`.

| Колонка | Тип | Описание |
|---------|-----|----------|
| `request_id` | `char(28)` PK, FK → `requests.request_id` | |
| `input_tokens` | `bigint unsigned` | Входные токены |
| `output_tokens` | `bigint unsigned` | Выходные токены |
| `cache_creation_5m_tokens` | `bigint unsigned` | Записано в кэш (5m TTL) |
| `cache_creation_1h_tokens` | `bigint unsigned` | Записано в кэш (1h TTL) |
| `cache_read_tokens` | `bigint unsigned` | Прочитано из кэша |
| `thinking_tokens` | `bigint unsigned` | Токены thinking |
| `server_tool_web_search_count` | `int unsigned` | Количество web_search вызовов |
| `server_tool_web_fetch_count` | `int unsigned` | Количество web_fetch вызовов |
| `server_tool_code_exec_count` | `int unsigned` | Количество code_execution вызовов |
| `server_tool_tool_search_count` | `int unsigned` | Количество tool_search вызовов |
| `cost_usd` | `decimal(12,8)` | Итоговая стоимость в USD |
| `cost_breakdown` | `json` | Детализация стоимости по категориям |
| `iterations_json` | `json` nullable | Итерации (для agentic loops) |
| `rate_limit_headers` | `json` nullable | Заголовки rate limit от Anthropic |

---

## Схема `request_raw`

Полные тела запроса и ответа. Связан 1:1 с `requests`.

| Колонка | Тип | Описание |
|---------|-----|----------|
| `request_id` | `char(28)` PK, FK → `requests.request_id` | |
| `request_payload` | `longtext` | Полное тело запроса (JSON) |
| `response_payload` | `longtext` nullable | Полное тело ответа (JSON). `null` если запрос ещё обрабатывается |
| `retention_until` | `datetime` | Дата автоматического удаления |

---

## Lifecycle

1. **Создание**: запись в `requests` создаётся при приёме запроса. `request_raw` создаётся одновременно с телом запроса. Колонка `retention_until` заполняется как `created_at + raw_log_retention_days` для справки, но самим cleanup не используется.
2. **Обновление**: после получения ответа от Anthropic заполняются `response_payload` в `request_raw`, `request_usage`, и поля `completed_at`/`http_status`/`status` в `requests`.
3. **Очистка**: scheduled команда `requests:cleanup` удаляет записи по `requests.created_at` -- см. Retention policy ниже.

---

## Retention policy

Удаление выполняется ежедневно командой `requests:cleanup` (03:00) по полю `requests.created_at`:

- `request_raw`: старше `raw_log_retention_days` (по умолчанию 14, настраивается в `config/llm.php`).
- `request_usage` и `requests`: старше `session_default_ttl_days` (по умолчанию 30).
- `async_pending`: записи с `expires_at` старше 1 дня.

Если нужен длительный audit trail, увеличьте `session_default_ttl_days` либо организуйте архивирование вручную перед истечением TTL.

---

## Доступ для debug

Поиск запроса по ID:

```sql
SELECT r.*, ru.input_tokens, ru.output_tokens, ru.cost_usd
FROM requests r
LEFT JOIN request_usage ru ON ru.request_id = r.request_id
WHERE r.request_id = 'req_...';
```

Получение сырого payload:

```sql
SELECT request_payload, response_payload
FROM request_raw
WHERE request_id = 'req_...';
```

Запросы клиента за период:

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

- `request_payload` и `response_payload` могут содержать персональные данные пользователей клиента.
- Не копировать сырые данные в логи, тикеты или внешние системы без очистки PII.
- При GDPR-запросе на удаление: удалить записи `request_raw` по `client_id` через JOIN с `requests`. Структурированные записи `requests` и `request_usage` не содержат PII (только токены, стоимость, метаданные).
- Доступ к `request_raw` ограничить только инженерам с соответствующими правами.
