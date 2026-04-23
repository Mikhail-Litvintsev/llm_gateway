# CLI-команды

## Управление клиентами

### `client:create`

Создание нового API-клиента с ключом и signing secret.

```bash
php artisan client:create {name}
    [--model-alias=]
    [--rate-limit=]
    [--monthly-cap=]
    [--features=*]
```

| Параметр | Описание |
|----------|----------|
| `name` | Имя клиента (обязательно) |
| `--model-alias` | Дефолтный алиас модели |
| `--rate-limit` | RPM (requests per minute) |
| `--monthly-cap` | Месячный лимит в USD |
| `--features` | Разрешённые фичи (через запятую) |

Выводит API key (`gw_live_...`) и signing secret. Выводятся однократно.

Файл: `app/Console/Commands/ClientCreate.php`

---

### `client:show`

Просмотр информации о клиенте.

```bash
php artisan client:show {client_id}
```

Файл: `app/Console/Commands/ClientShow.php`

---

### `client:rotate-key`

Ротация API-ключа клиента. Старый ключ остаётся валидным в течение grace period.

```bash
php artisan client:rotate-key {client_id}
```

Файл: `app/Console/Commands/ClientRotateKey.php`

---

### `client:rotate-secret`

Ротация signing secret для webhook. Grace period 24 часа -- оба секрета принимаются.

```bash
php artisan client:rotate-secret {client_id}
```

Файл: `app/Console/Commands/ClientRotateSecret.php`

---

### `client:enable-feature`

Включение feature для клиента.

```bash
php artisan client:enable-feature {client_id} {feature}
```

Файл: `app/Console/Commands/ClientEnableFeature.php`

---

### `client:disable-feature`

Отключение feature для клиента.

```bash
php artisan client:disable-feature {client_id} {feature}
```

Файл: `app/Console/Commands/ClientDisableFeature.php`

---

## Claude API

### `claude:status`

Статус подключения к Anthropic API: очереди, rate limit budget, failed jobs, streaming pool.

```bash
php artisan claude:status
```

Файл: `app/Console/Commands/ClaudeStatus.php`

---

### `claude:resume`

Снимает глобальную паузу на обращения к Claude API (удаляет Redis-ключ `claude:pause:global`). Следующий healthcheck-пинг произойдёт в течение минуты.

```bash
php artisan claude:resume
```

Файл: `app/Console/Commands/ClaudeResume.php`

---

### `claude:price-check`

Проверка актуальных цен моделей.

```bash
php artisan claude:price-check
```

Файл: `app/Console/Commands/ClaudePriceCheck.php`

---

### `claude:sync-capabilities`

Синхронизация capabilities моделей с Anthropic API (context window, max output, поддерживаемые фичи).

```bash
php artisan claude:sync-capabilities
```

Файл: `app/Console/Commands/SyncClaudeCapabilities.php`

---

### `claude:cleanup-files`

Удаление осиротевших файлов (без владельца или с истёкшим TTL).

```bash
php artisan claude:cleanup-files
```

Файл: `app/Console/Commands/Claude/CleanupOrphanedFilesScheduled.php`

---

### `claude:flush-accumulator`

Ручной flush batch accumulator (сброс накопленных элементов).

```bash
php artisan claude:flush-accumulator
```

Файл: `app/Console/Commands/Claude/FlushBatchAccumulatorScheduled.php`

---

### `claude:poll-batches`

Ручной опрос статуса активных batch.

```bash
php artisan claude:poll-batches
```

Файл: `app/Console/Commands/Claude/PollBatchesScheduled.php`

---

## Обслуживание

### `requests:cleanup`

TTL-очистка записей по `created_at` из таблицы `requests`:

- `async_pending` -- записи с истекшим `expires_at` (старше 1 дня).
- `request_raw` -- старше `llm.raw_log_retention_days` (по умолчанию 14).
- `request_usage` и `requests` -- старше `llm.session_default_ttl_days` (по умолчанию 30).

```bash
php artisan requests:cleanup
```

Файл: `app/Console/Commands/RequestsCleanup.php`

---

### `webhook:cleanup-expired-secrets`

Удаление истёкших signing secrets (после grace period).

```bash
php artisan webhook:cleanup-expired-secrets
```

Файл: `app/Console/Commands/WebhookCleanupExpiredSecrets.php`

---

## Тестирование

### `llm:create-test-db`

Создание тестовой БД `llm_gateway_test`.

```bash
php artisan llm:create-test-db
    [--host=127.0.0.1]
    [--port=3307]
    [--root-password=root_secret]
```

Файл: `app/Console/Commands/CreateTestDatabase.php`
