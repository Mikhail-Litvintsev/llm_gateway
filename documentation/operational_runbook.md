# Operational Runbook -- LLM Gateway v4

Целевая аудитория: дежурные инженеры, обрабатывающие инциденты.

---

## Содержание

1. [Как читать claude:status](#1-как-читать-claudestatus)
2. [Что делать при claude:status = degraded](#2-что-делать-при-claudestatus--degraded)
3. [Как вручную дергать claude:resume](#3-как-вручную-дергать-clauderesume)
4. [Debug failed webhook deliveries](#4-debug-failed-webhook-deliveries)
5. [Debug stuck batch](#5-debug-stuck-batch)
6. [Как обновлять model aliases](#6-как-обновлять-model-aliases)
7. [Как обновлять pricing](#7-как-обновлять-pricing)
8. [Как обновлять beta headers](#8-как-обновлять-beta-headers)
9. [Как чистить orphaned files](#9-как-чистить-orphaned-files)
10. [Как извлекать данные о клиенте по request_id](#10-как-извлекать-данные-о-клиенте-по-request_id)
11. [Drop legacy tables safeguard](#11-drop-legacy-tables-safeguard)
12. [Streaming pool мониторинг](#12-streaming-pool-мониторинг)
13. [Эскалация: когда писать в Anthropic support](#13-эскалация-когда-писать-в-anthropic-support)

---

## 1. Как читать claude:status

```bash
php artisan claude:status
```

### Что выводит

Команда читает кеш из Redis (`claude:healthcheck:anthropic`) и ключи rate limit (`claude_rl:*`).

**Блок 1 -- Anthropic API Status**

Состояние upstream:
- `OK` (зеленый) -- Anthropic API отвечает нормально, latency в пределах нормы
- `DEGRADED` (желтый) -- нет свежих данных о пинге, либо Anthropic отвечает с повышенной latency
- `DOWN` (красный) -- Anthropic не отвечает, последний пинг завершился ошибкой

Поля:
- **Latency** -- время ответа Anthropic healthcheck в миллисекундах
- **Error** -- текст ошибки (только при DEGRADED/DOWN)
- **Last check** -- время последней проверки

**Блок 2 -- Rate Limit Snapshots**

Таблица по моделям с колонками:
- **Model** -- snapshot модели (например `claude-sonnet-4-6`)
- **Input Tokens (rem/lim)** -- оставшиеся / лимит входных токенов
- **Output Tokens (rem/lim)** -- оставшиеся / лимит выходных токенов
- **Requests (rem/lim)** -- оставшиеся / лимит запросов
- **Recorded At** -- когда снимок был записан

Если данных нет -- выводится `No rate limit data cached.`

**Блок 3 -- Global Pause**

Если установлен Redis-ключ `claude:pause:global`, выводится предупреждение:
```
Global pause is ACTIVE. Run `claude:resume` to clear.
```

### Exit codes

- `0` -- статус OK или DEGRADED
- `1` -- статус DOWN

Для мониторинга: используйте exit code в cron/healthcheck скриптах.

---

## 2. Что делать при claude:status = degraded

Пошаговое дерево решений:

### Шаг 1. Проверить очереди

```bash
php artisan queue:monitor high,default,low,batch
```

Если backlog растет (> 100 jobs за 5 минут) -- запросы копятся. Перейти к шагу 2.

### Шаг 2. Проверить failed jobs

```sql
SELECT id, uuid, connection, queue, payload, exception, failed_at
FROM failed_jobs
ORDER BY failed_at DESC
LIMIT 20;
```

Если есть свежие ошибки:
- `ProcessAsyncMessage` с ошибкой timeout -- upstream медленный, перейти к шагу 4
- `DeliverWebhook` -- клиент не принимает callback, см. [секцию 4](#4-debug-failed-webhook-deliveries)
- Другие -- читать `exception`, искать root cause в логах:

```bash
tail -200 storage/logs/llm-*.log | grep -i error
```

### Шаг 3. Проверить streaming pool (listen_queue)

См. [секцию 12](#12-streaming-pool-мониторинг). Если `listen_queue > 5` устойчиво более 2 минут -- streaming pool перегружен.

### Шаг 4. Проверить upstream Anthropic

```bash
curl -s -o /dev/null -w "%{http_code} %{time_total}s" \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  https://api.anthropic.com/v1/models
```

- HTTP 200, time < 2s -- Anthropic в норме, проблема на нашей стороне
- HTTP 429 -- rate limit, проверить бюджет в `claude:status`
- HTTP 5xx -- проблема у Anthropic, перейти к шагу 6
- Timeout -- сетевая проблема или даунтайм Anthropic

Также проверить: https://status.anthropic.com

### Шаг 5. Проверить rate limit бюджет по клиентам

```sql
SELECT c.id, c.name, c.rate_limit_rpm, c.monthly_spend_cap_usd, c.current_month_spend_usd
FROM clients c
WHERE c.deleted_at IS NULL
ORDER BY c.current_month_spend_usd DESC
LIMIT 10;
```

Если `current_month_spend_usd` близок к `monthly_spend_cap_usd` -- клиент скоро будет отклонен по лимиту. Это штатное поведение.

Если rate limit данные в `claude:status` показывают `requests_remaining` близкий к 0 -- мы исчерпываем лимит Anthropic.

### Шаг 6. Эскалация

Если проблема не на нашей стороне и Anthropic отвечает ошибками -- см. [секцию 13](#13-эскалация-когда-писать-в-anthropic-support).

Если проблема на нашей стороне -- эскалировать команде разработки с данными из шагов 1-5.

---

## 3. Как вручную дергать claude:resume

### Что делает

Удаляет Redis-ключ `claude:pause:global`. После удаления запросы к Anthropic API возобновляются. Следующий healthcheck пинг произойдет в течение 1 минуты.

### Когда применять

- После того как upstream Anthropic восстановился (проверено через `claude:status` или вручную через curl)
- После ложного срабатывания circuit breaker
- При ручном снятии глобальной паузы

### Синтаксис

```bash
php artisan claude:resume
```

Вывод при активной паузе:
```
Global pause cleared. Requests will resume.
Next healthcheck ping will run within 1 minute.
```

Вывод если паузы не было:
```
No pause was active.
Next healthcheck ping will run within 1 minute.
```

### Важно

- Команда не имеет флагов и аргументов
- Действие мгновенное и необратимое -- если нужно снова поставить паузу, это делается установкой ключа `claude:pause:global` в Redis вручную
- Перед снятием паузы убедитесь что Anthropic действительно работает (шаг 4 из секции 2)

---

## 4. Debug failed webhook deliveries

### Где смотреть

Webhook доставка логируется в таблице `async_pending`.

### Найти по request_id

```sql
SELECT
    ap.request_id,
    ap.callback_url,
    ap.status,
    ap.callback_attempts,
    ap.next_attempt_at,
    ap.expires_at,
    ap.created_at,
    ap.updated_at
FROM async_pending ap
WHERE ap.request_id = '<REQUEST_ID>';
```

Статусы:
- `queued` -- ожидает первой попытки
- `processing` -- была неудачная попытка, ожидает следующей по backoff
- `delivered` -- успешно доставлено
- `exhausted` -- исчерпаны все попытки (по умолчанию 10)

### Проверить историю попыток

`callback_attempts` показывает сколько попыток было. `next_attempt_at` -- когда следующая.

Backoff: экспоненциальный, начальная задержка 10 секунд, максимум 3600 секунд.
Формула: `min(10 * 2^(attempts-1), 3600)`.

Расписание попыток: 10s, 20s, 40s, 80s, 160s, 320s, 640s, 1280s, 2560s, 3600s.

### Проверить что запрос завершился

```sql
SELECT r.request_id, r.status, r.error_type, r.error_message, r.completed_at
FROM requests r
WHERE r.request_id = '<REQUEST_ID>';
```

Если `status = failed_callback_delivery` -- все попытки исчерпаны. Если `status = completed` -- запрос успешен, но webhook не доставлен (смотреть `async_pending`).

### Лог ошибок

```bash
grep '<REQUEST_ID>' storage/logs/llm-*.log
```

При `exhausted` статусе пишется лог с уровнем `error` и деталями:
```
Webhook delivery exhausted {"request_id": "...", "client_id": ..., "attempts": 10}
```

### Ручной ретриггер

Сбросить статус и попытки, чтобы webhook был подхвачен заново:

```sql
UPDATE async_pending
SET status = 'queued',
    callback_attempts = 0,
    next_attempt_at = NOW(),
    updated_at = NOW()
WHERE request_id = '<REQUEST_ID>'
  AND status = 'exhausted';
```

Затем задиспатчить job вручную:

```bash
php artisan tinker --execute="App\Jobs\DeliverWebhook::dispatch('<REQUEST_ID>')->onQueue('default');"
```

### Если клиент сменил callback URL

Проверить текущие URL клиента:

```sql
SELECT * FROM client_callback_urls WHERE client_id = <CLIENT_ID>;
```

Если URL в `async_pending.callback_url` устарел, обновить:

```sql
UPDATE async_pending
SET callback_url = '<NEW_URL>',
    status = 'queued',
    callback_attempts = 0,
    next_attempt_at = NOW(),
    updated_at = NOW()
WHERE request_id = '<REQUEST_ID>';
```

И задиспатчить job заново (см. выше).

---

## 5. Debug stuck batch

### Симптомы

- Batch в статусе `in_progress` более 24 часов
- `claude:poll-batches` не переводит его в завершенный статус
- Клиент жалуется на зависший batch

### Диагностика

```sql
SELECT
    b.id,
    b.batch_id,
    b.anthropic_batch_id,
    b.client_id,
    b.status,
    b.request_count,
    b.succeeded_count,
    b.errored_count,
    b.cancelled_count,
    b.expired_count,
    b.poll_attempts,
    b.submitted_at,
    b.completed_at,
    b.created_at,
    b.updated_at
FROM batches b
WHERE b.status = 'in_progress'
  AND b.submitted_at < NOW() - INTERVAL 24 HOUR
ORDER BY b.submitted_at ASC;
```

### Проверить статус напрямую в Anthropic

```bash
curl -s \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  "https://api.anthropic.com/v1/messages/batches/<ANTHROPIC_BATCH_ID>" | jq .
```

Возможные `processing_status`:
- `in_progress` -- Anthropic все еще обрабатывает
- `ended` -- завершен, но мы пропустили (баг в polling или cooldown)
- 404 -- batch не найден на стороне Anthropic

### Проверить элементы batch

```sql
SELECT
    bi.status,
    COUNT(*) as cnt
FROM batch_items bi
WHERE bi.batch_id = <BATCH_ID>
GROUP BY bi.status;
```

### Если Anthropic вернул `ended`, а у нас все еще `in_progress`

Проверить не стоит ли cooldown на polling:

```bash
php artisan tinker --execute="echo \Illuminate\Support\Facades\Cache::get('claude:batch-poll-cooldown') ? 'cooldown active' : 'no cooldown';"
```

Принудительно запустить poll:

```bash
php artisan claude:poll-batches
```

### Force close (крайняя мера)

Если batch завис на стороне Anthropic и не двигается более 48 часов:

```sql
UPDATE batches
SET status = 'failed',
    completed_at = NOW(),
    updated_at = NOW()
WHERE batch_id = '<BATCH_ID>'
  AND status = 'in_progress';
```

Сообщить клиенту, что batch был принудительно закрыт. Элементы в статусе `pending` нужно будет отправить заново.

---

## 6. Как обновлять model aliases

Подробнее: `documentation/internal_logic.md`, секция 20.

### Чеклист

1. Обновить `config/llm.php` -- секция `claude.model_aliases` и при необходимости `claude.model_capabilities`
2. Запустить синхронизацию:
   ```bash
   php artisan claude:sync-capabilities
   ```
   Команда запросит live capabilities из Anthropic и покажет drift (расхождения с конфигом).
3. Запустить тесты:
   ```bash
   php artisan test --testsuite=Unit
   php artisan test --testsuite=Feature
   ```
4. Деплой по стандартной процедуре
5. Мониторить 24 часа: `claude:status`, логи ошибок, rate limit бюджет

### Важно

- Alias (например `claude-sonnet`) остается стабильным, меняется только snapshot (например `claude-sonnet-4-6` -> `claude-sonnet-4-7`)
- Клиенты используют alias, не snapshot -- обновление для них прозрачно
- После обновления snapshot rate limit бюджет считается по новой модели

---

## 7. Как обновлять pricing

Подробнее: `documentation/internal_logic.md`, секция 19.

### Чеклист

1. Обновить `config/llm.php` -- секция `claude.pricing`
2. Проверить текущие цены:
   ```bash
   php artisan claude:price-check
   ```
   Выведет таблицу: alias, snapshot, input/output/cache/batch цены за 1M токенов, server tools pricing.
3. Запустить тесты:
   ```bash
   php artisan test --testsuite=Unit
   ```
4. Уведомить клиентов (шаблон ниже)
5. Деплой
6. Мониторить spend после обновления:
   ```sql
   SELECT c.name, c.current_month_spend_usd, c.monthly_spend_cap_usd
   FROM clients c
   WHERE c.deleted_at IS NULL
   ORDER BY c.current_month_spend_usd DESC;
   ```

### Шаблон уведомления клиентов

**Русская версия:**

```
Тема: Изменение тарифов LLM Gateway -- вступает в силу [ДАТА]

Уважаемые коллеги,

Сообщаем об изменении тарифов на использование LLM Gateway,
вступающем в силу с [ДАТА].

Затронутые модели: [СПИСОК МОДЕЛЕЙ, например: claude-sonnet, claude-opus]

Старые цены (за 1M токенов):
- Input: $[СТАРАЯ_ЦЕНА]
- Output: $[СТАРАЯ_ЦЕНА]

Новые цены (за 1M токенов):
- Input: $[НОВАЯ_ЦЕНА]
- Output: $[НОВАЯ_ЦЕНА]

Полная таблица цен определена в `config/llm.php` -> `claude.pricing`. Проверить локально: `php artisan claude:price-check`.

Если у вас настроены бюджетные лимиты (monthly_spend_cap_usd),
рекомендуем пересмотреть их с учетом новых тарифов.

По вопросам обращайтесь: [КОНТАКТ]
```

**English version:**

```
Subject: LLM Gateway pricing update -- effective [DATE]

Dear team,

We are updating the pricing for LLM Gateway usage,
effective [DATE].

Affected models: [MODEL LIST, e.g.: claude-sonnet, claude-opus]

Previous pricing (per 1M tokens):
- Input: $[OLD_PRICE]
- Output: $[OLD_PRICE]

New pricing (per 1M tokens):
- Input: $[NEW_PRICE]
- Output: $[NEW_PRICE]

Full pricing table available via API: GET /internal/stats

If you have budget caps configured (monthly_spend_cap_usd),
we recommend reviewing them in light of the new rates.

Questions: [CONTACT]
```

---

## 8. Как обновлять beta headers

Подробнее: `documentation/internal_logic.md`, секция 18.

### Чеклист

1. Обновить `config/llm.php` -- секция `claude.beta_headers`
   Текущие headers:
   - `files_api` -- `files-api-2025-04-14`
   - `compaction` -- `compact-2026-01-12`
   - `context_management` -- `context-management-2025-06-27`
   - `output_300k` -- `output-300k-2026-03-24`
   - `mcp_client` -- `mcp-client-2025-11-20`
   - `fast_mode` -- `fast-mode-2026-02-01`
   - `computer_use` -- `computer-use-2025-01-24`
   - `skills` -- `skills-2025-10-02`

2. Запустить тесты:
   ```bash
   php artisan test --testsuite=Unit
   ```

3. Деплой

4. Проверить что запросы проходят:
   ```bash
   php artisan claude:status
   ```
   Если статус OK -- headers корректны. Если DEGRADED/DOWN с ошибкой `invalid_request_error` -- header невалиден, откатить.

### Важно

- Anthropic периодически переводит beta features в GA и удаляет header. Устаревший header вызовет ошибку.
- При удалении header из конфига убедитесь, что feature доступна без него (проверить в Anthropic changelog).
- Порядок headers не имеет значения, они конкатенируются через запятую.

---

## 9. Как чистить orphaned files

### Автоматическая очистка

```bash
php artisan claude:cleanup-files
```

Команда выполняет два прохода:
1. **Hard-delete pass** -- удаляет записи из таблицы `files`, у которых `is_deleted = 1` и прошло более `hard_delete_grace_days` (по умолчанию 14 дней) с момента soft delete
2. **Unused alert pass** -- обнаруживает файлы, не используемые более `unused_alert_days` (по умолчанию 90 дней) и логирует предупреждение

### Ручная очистка SQL

Найти orphaned файлы (soft-deleted, старше grace period):

```sql
SELECT f.id, f.file_id, f.anthropic_file_id, f.filename, f.size_bytes, f.deleted_at
FROM files f
WHERE f.deleted_at IS NOT NULL
  AND f.deleted_at < NOW() - INTERVAL 14 DAY
ORDER BY f.size_bytes DESC;
```

Удалить конкретный файл:

```sql
DELETE FROM files WHERE file_id = '<FILE_ID>';
```

### Пересчет storage quota

После массовой очистки проверить суммарный размер файлов по клиенту:

```sql
SELECT
    f.client_id,
    c.name,
    COUNT(*) as file_count,
    SUM(f.size_bytes) as total_bytes,
    ROUND(SUM(f.size_bytes) / 1048576, 2) as total_mb
FROM files f
JOIN clients c ON f.client_id = c.id
WHERE f.deleted_at IS NULL
GROUP BY f.client_id, c.name
ORDER BY total_bytes DESC;
```

---

## 10. Как извлекать данные о клиенте по request_id

### Основная информация о запросе

```sql
SELECT
    r.request_id,
    r.client_id,
    c.name AS client_name,
    r.endpoint,
    r.mode,
    r.model_alias,
    r.model_snapshot,
    r.anthropic_request_id,
    r.status,
    r.http_status,
    r.error_type,
    r.error_message,
    r.service_tier_used,
    r.created_at,
    r.started_at,
    r.completed_at
FROM requests r
JOIN clients c ON r.client_id = c.id
WHERE r.request_id = '<REQUEST_ID>';
```

### Исходный payload и ответ

```sql
SELECT
    rr.request_id,
    rr.request_payload,
    rr.response_payload,
    rr.retention_until
FROM request_raw rr
WHERE rr.request_id = '<REQUEST_ID>';
```

Retention: `request_raw` хранится `raw_log_retention_days` дней (по умолчанию 14). После этого срока данные удаляются `requests:cleanup`.

### Cost breakdown

```sql
SELECT
    ru.request_id,
    ru.input_tokens,
    ru.output_tokens,
    ru.cache_creation_5m_tokens,
    ru.cache_creation_1h_tokens,
    ru.cache_read_tokens,
    ru.thinking_tokens,
    ru.server_tool_web_search_count,
    ru.server_tool_code_exec_count,
    ru.cost_usd,
    ru.cost_breakdown
FROM request_usage ru
WHERE ru.request_id = '<REQUEST_ID>';
```

`cost_breakdown` -- JSON с детализацией: input, output, cache_write_5m, cache_write_1h, cache_read, server tools.

### Webhook delivery log

```sql
SELECT
    ap.request_id,
    ap.callback_url,
    ap.status,
    ap.callback_attempts,
    ap.next_attempt_at,
    ap.expires_at
FROM async_pending ap
WHERE ap.request_id = '<REQUEST_ID>';
```

### PII

Таблица `request_raw` содержит полные payload запросов и ответов, включая пользовательский контент. При предоставлении данных третьим лицам или при запросе клиента на удаление:

- Убедитесь что запрос авторизован
- Не копируйте `request_payload`/`response_payload` в незащищенные каналы
- При необходимости удалить:
  ```sql
  DELETE FROM request_raw WHERE request_id = '<REQUEST_ID>';
  ```

---

## 11. Drop legacy tables safeguard

**КРИТИЧНО.** Одноразовая операция удаления устаревших таблиц из предыдущей версии схемы.

### Какие таблицы удаляются

Миграция `2026_05_01_000001_drop_legacy_tables` удаляет:
- `session_history`
- `pending_responses`
- `pending_prompts`
- `raw_responses`
- `response_log`
- `request_log`
- `callback_urls`
- `api_clients`
- `jobs`

### Защитный механизм

Миграция защищена переменной окружения. Без нее `php artisan migrate` выбросит `RuntimeException` и остановится.

### Пошаговая инструкция

**Шаг 1.** Установить переменную окружения:

```bash
export CLAUDE_ALLOW_LEGACY_DROP=yes-i-confirm-data-loss-2026-05
```

Или добавить в `.env`:
```
CLAUDE_ALLOW_LEGACY_DROP=yes-i-confirm-data-loss-2026-05
```

Значение должно быть **точно** `yes-i-confirm-data-loss-2026-05`. Любое другое значение не пройдет проверку.

**Шаг 2.** Запустить миграцию:

```bash
php artisan migrate --force
```

`--force` обязателен в production.

**Шаг 3.** Удалить переменную окружения:

```bash
unset CLAUDE_ALLOW_LEGACY_DROP
```

Или удалить строку из `.env`. Переменная не должна оставаться в окружении после миграции.

**Шаг 4.** Верифицировать:

```sql
SHOW TABLES;
```

Убедиться что таблицы `api_clients`, `callback_urls`, `request_log`, `response_log`, `raw_responses`, `pending_prompts`, `pending_responses`, `session_history`, `jobs` отсутствуют.

### Важно

- Это одноразовая операция. После выполнения миграция помечается как выполненная в `migrations` и больше не запускается.
- Откат (`migrate:rollback`) невозможен -- миграция `down()` бросает RuntimeException. Восстановление только из бекапа.
- Защитный механизм реализован через env-переменную намеренно: это единственный способ убедиться, что оператор осознанно подтвердил удаление данных.
- Перед выполнением убедитесь что бекап базы данных создан.

---

## 12. Streaming pool мониторинг

### Как устроен streaming

Streaming-запросы обрабатываются php-fpm процессами напрямую (не через очереди). Каждый streaming-запрос занимает один php-fpm worker на все время стриминга (timeout до 1800 секунд).

### Как проверить listen_queue

```bash
docker exec llm-gateway-php-fpm bash -c "SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect /var/run/php-fpm.sock"
```

Или через nginx (если настроен endpoint):
```bash
curl -s http://localhost:8080/fpm-status
```

Ключевые метрики:
- **listen queue** -- количество запросов, ожидающих свободного worker
- **active processes** -- количество занятых workers
- **idle processes** -- количество свободных workers
- **max active processes** -- пиковое значение за время работы

### Триггеры алертов

| Условие | Уровень | Действие |
|---------|---------|----------|
| `listen_queue > 5` устойчиво 2 минуты | WARNING | Проверить количество streaming-запросов |
| `listen_queue > 10` устойчиво 1 минуту | CRITICAL | Масштабировать pool |
| `idle processes = 0` при `listen_queue > 0` | CRITICAL | Все workers заняты, новые запросы блокируются |

### Как масштабировать

Увеличить `pm.max_children` в конфигурации php-fpm:

1. Отредактировать `docker/php-fpm/www.conf` (или аналогичный файл конфигурации):
   ```ini
   pm.max_children = <НОВОЕ_ЗНАЧЕНИЕ>
   ```

2. Перезапустить php-fpm контейнер:
   ```bash
   docker compose restart php-fpm
   ```

### Факторы риска

- Каждый streaming worker держит соединение до 1800 секунд (30 минут) -- `config/llm.php` -> `claude.timeouts.streaming`
- При 20 workers и среднем стриме 60 секунд -- пропускная способность ~20 rps streaming
- При всплеске длинных стримов (thinking с extended output) workers исчерпываются быстрее
- Увеличение `pm.max_children` требует пропорционального увеличения RAM (каждый worker ~50-100MB)
- Мониторьте connection_aborted -- если клиент отключается, worker все равно дочитывает ответ от Anthropic для корректного биллинга

---

## 13. Эскалация: когда писать в Anthropic support

### Симптомы для эскалации

| Симптом | Порог | Откуда данные |
|---------|-------|---------------|
| HTTP 5xx от Anthropic | > 1% от всех запросов устойчиво > 5 минут | `requests.http_status`, логи |
| HTTP 429 на все запросы | Все модели, все клиенты одновременно | `claude:status` rate limit snapshot |
| Latency spike | > 3x от baseline (baseline ~2-5s для sonnet) | `claude:status` latency, `requests.started_at`/`completed_at` |
| Batch не завершается | > 48 часов в статусе `in_progress` | `batches.submitted_at` |
| Files API ошибки | Стабильные 4xx/5xx при загрузке файлов | Логи |

### Что включить в тикет

1. **Request IDs** -- наши `request_id` и `anthropic_request_id` из таблицы `requests`:
   ```sql
   SELECT request_id, anthropic_request_id, http_status, error_type, error_message, created_at
   FROM requests
   WHERE http_status >= 500
     AND created_at > NOW() - INTERVAL 1 HOUR
   ORDER BY created_at DESC
   LIMIT 20;
   ```

2. **Timestamps** -- точный интервал проблемы в UTC

3. **Repro payload** -- минимальный запрос, воспроизводящий проблему (из `request_raw.request_payload`):
   ```sql
   SELECT rr.request_payload
   FROM request_raw rr
   JOIN requests r ON rr.request_id = r.request_id
   WHERE r.http_status >= 500
     AND r.created_at > NOW() - INTERVAL 1 HOUR
   LIMIT 1;
   ```
   Убрать из payload чувствительные данные клиента перед отправкой.

4. **Error response** -- тело ответа от Anthropic (из `request_raw.response_payload`)

5. **Частота** -- процент ошибок и количество затронутых запросов

6. **Organization ID** -- из `requests.anthropic_organization_id`

### Контакты Anthropic

- Support portal: https://support.anthropic.com
- API status page: https://status.anthropic.com
- Для критических инцидентов (полный даунтайм): указать `Severity: Critical` в тикете
