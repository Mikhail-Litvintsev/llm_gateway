# Artisan-команды проекта

## Управление клиентами

### `llm:create-client`

Создание нового API-клиента.

```bash
docker exec llm_gateway php artisan llm:create-client {name} [--rate-limit=60] [--providers=] [--no-dev-mode]
```

| Аргумент/Опция | Описание |
|----------------|----------|
| `name` | Имя клиента (обязательно) |
| `--rate-limit=60` | Лимит запросов в минуту (по умолчанию 60) |
| `--providers=` | Разрешенные провайдеры через запятую (например `claude,openai`) |
| `--no-dev-mode` | Создать с выключенным dev_mode (по умолчанию dev_mode=true) |

Выводит: имя, ID, статус dev_mode, API Key и Signing Secret.

---

### `llm:add-callback-url`

Добавление разрешенного callback URL клиенту.

```bash
docker exec llm_gateway php artisan llm:add-callback-url {client_name} {url}
```

URL должен использовать `https://`.

---

### `llm:rotate-key`

Ротация API-ключа клиента с grace period.

```bash
docker exec llm_gateway php artisan llm:rotate-key {client_name} [--ttl=24]
```

| Опция | Описание |
|-------|----------|
| `--ttl=24` | Время жизни старого ключа в часах (по умолчанию 24) |

Старый ключ сохраняется в `previous_key_hash` и принимается в течение TTL.

---

### `llm:toggle-dev-mode`

Переключение dev_mode клиента.

```bash
docker exec llm_gateway php artisan llm:toggle-dev-mode {client} [--enable] [--disable]
```

| Опция | Описание |
|-------|----------|
| `{client}` | ID или имя клиента |
| `--enable` | Принудительно включить |
| `--disable` | Принудительно выключить |

Без флагов — переключает текущее состояние.

---

## Управление провайдерами

### `llm:provider-status`

Показывает статус всех провайдеров (active/paused), причину паузы и настроенный rate limit.

```bash
docker exec llm_gateway php artisan llm:provider-status
```

---

### `llm:resume-provider`

Возобновление работы приостановленного провайдера. Провайдер автоматически ставится на паузу при получении ошибки rate limit (HTTP 429) или нехватки средств (HTTP 402) от API провайдера. Все запросы к приостановленному провайдеру остаются в очереди и ожидают ручного возобновления.

```bash
docker exec llm_gateway php artisan llm:resume-provider {provider}
```

| Аргумент | Описание |
|----------|----------|
| `provider` | Имя провайдера (`claude`, `openai`, `deepseek`, `gemini`, `mistral`) или `all` для возобновления всех |

---

## Мониторинг и статистика

### `llm:stats`

Статистика запросов.

```bash
docker exec llm_gateway php artisan llm:stats [--client=] [--from=] [--to=]
```

| Опция | Описание |
|-------|----------|
| `--client=` | Фильтр по имени клиента |
| `--from=` | Начало периода (Y-m-d) |
| `--to=` | Конец периода (Y-m-d) |

Выводит: общее количество запросов, количество dev_mode, разбивку по статусам и провайдерам, статистику latency, топ ошибок.

---

### `llm:claude-token-budget`

Показывает текущий остаток токенового бюджета Claude (данные из Redis, записанные из заголовков ответов Anthropic API).

```bash
docker exec llm_gateway php artisan llm:claude-token-budget
```

Выводит таблицу:
- **Metric** — Input/Output tokens per minute
- **Limit** — настроенный лимит из конфига
- **Remaining** — оставшийся бюджет
- **Used %** — процент использования
- **Resets At** — время сброса лимита (Y-m-d H:i:s)

Дополнительно показывает статус провайдера (active/paused) и причину паузы.

Если данных в кэше нет (ни одного запроса к Claude или данные истекли) — выводит предупреждение.

---

## Обслуживание данных

### `llm:cleanup-expired`

Очистка устаревших данных. Запускается автоматически по расписанию (каждый час).

```bash
docker exec llm_gateway php artisan llm:cleanup-expired
```

Удаляет:
- `pending_prompts` с истекшим `expires_at`
- `pending_responses` с истекшим `expires_at`
- Доставленные `pending_responses` старше 1 дня
- Помечает зависшие `accepted` запросы без pending-данных старше 3 дней как `timeout`

---

### `llm:mark-timed-out`

Пометка зависших запросов. Запускается автоматически каждые 5 минут.

```bash
docker exec llm_gateway php artisan llm:mark-timed-out
```

Помечает запросы в статусе `processing` дольше 30 минут как `timeout` с кодом ошибки `PROVIDER_TIMEOUT`.

---

### `llm:retry-callbacks`

Повторная отправка неудачных callback-ов. Запускается автоматически каждую минуту.

```bash
docker exec llm_gateway php artisan llm:retry-callbacks
```

Находит `pending_responses` со статусом `pending` и `next_retry_at <= now()`, отправляет `DeliverCallback` job.

---

## Деплой

### `llm:deploy-optimize`

Оптимизация для production.

```bash
docker exec llm_gateway php artisan llm:deploy-optimize
```

Выполняет: `config:cache`, `route:cache`, `event:cache`, `view:cache`.

---

## Тестирование

### `llm:create-test-database`

Создание тестовой БД.

```bash
docker exec llm_gateway php artisan llm:create-test-database [--host=127.0.0.1] [--port=3307] [--root-password=root_secret]
```

Создает БД `llm_gateway_test` и выдает права пользователю `llm_user`.

---

## Расписание (routes/console.php)

| Команда | Интервал | Опции |
|---------|----------|-------|
| `llm:retry-callbacks` | Каждую минуту | — |
| `llm:cleanup-expired` | Каждый час | `withoutOverlapping()`, лог в `storage/logs/cleanup.log` |
| `llm:mark-timed-out` | Каждые 5 минут | `withoutOverlapping()` |
