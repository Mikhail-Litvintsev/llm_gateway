# LLM Gateway

Claude-only gateway v4. JSON-нативный API в формате Anthropic Messages (pass-through). Единственный провайдер -- Claude (Anthropic).

Поддерживаемые режимы: sync, sync streaming (SSE), async webhook, batch (immediate + accumulator), sessions (multi-turn с compaction и context editing), files, server-side tools, MCP connector, skills.

## Технологии

- PHP 8.4, Laravel 13
- MySQL 8.4 (хранение данных, аудит, биллинг)
- Redis 7 (очереди, кэш, rate limiting)
- Docker Compose (7 контейнеров: php-fpm default pool, php-fpm streaming pool, nginx, mysql, redis, queue worker, scheduler)

## Архитектурные принципы

- SOLID, DRY, KISS
- Паттерны: Strategy, Factory, DTO, Enum, Facade/Orchestrator
- Запрет слова `Service` в именах классов
- Логика группируется в `app/Components/{Раздел}/` с подпапками `DTO/`, `Enums/`, `Contracts/`
- Главный класс каждого раздела -- оркестратор-фасад в корне папки раздела
- JSON-first (никаких XML, никаких мульти-провайдеров)
- Thin proxy + value-add: sync -- байт-в-байт pass-through, value-add только в headers, логах, биллинге

## Поддерживаемые модели

| Алиас | Snapshot | Context window | Max output | Tier |
|-------|----------|----------------|------------|------|
| `claude-opus` | `claude-opus-4-6` | 1M | 128K (batch: 300K) | top |
| `claude-sonnet` | `claude-sonnet-4-6` | 1M | 64K (batch: 300K) | balanced |
| `claude-haiku` | `claude-haiku-4-5` | 200K | 64K (batch: 64K) | fast/cheap |

Snapshot-имена настраиваются через env (`CLAUDE_OPUS_MODEL`, `CLAUDE_SONNET_MODEL`, `CLAUDE_HAIKU_MODEL`). Дефолтный алиас: `claude-sonnet`.

## Структура Components

```
app/Components/
  Auth/              -- Аутентификация (API key hashing, key rotation, pepper)
  Authorization/     -- Авторизация (feature gates, budget checks, workspace access)
  Billing/           -- Биллинг (cost estimation, usage tracking, hard caps)
  Caching/           -- Prompt caching (manual breakpoints, auto top-level injection)
  Claude/            -- Ядро: HTTP-клиент к Anthropic API, batch, DTO, payload builder, response parser
  Delivery/          -- Доставка webhook с retry, HMAC-SHA256 подписью, grace period
  DevMode/           -- Stub-ответы для тестирования без реальных API-вызовов
  Healthcheck/       -- Health check и статистика (/internal/health, /internal/stats)
  Logging/           -- Аудит-логирование запросов/ответов в БД
  Pricing/           -- Расчет стоимости (input/output/cache/batch/tools/fast mode)
  RateLimiting/      -- Rate limiting per API key (Redis, local enforcement)
  Routing/           -- Резолвинг моделей (alias -> snapshot), workspace resolution
  Sessions/          -- Multi-turn сессии с compaction, context editing, memory files
  Skills/            -- Pre-built skills (xlsx, docx, pptx, pdf)
  Usage/             -- Отчеты по использованию для клиентов
  Validation/        -- Валидация входящих запросов
```

## Возможности Claude API

| Возможность | Статус |
|-------------|--------|
| Sync messages | GA |
| Sync streaming (SSE) | GA |
| Async webhook | GA |
| Prompt caching (manual breakpoints) | GA |
| Prompt caching (auto top-level) | GA |
| Batch API (immediate) | GA |
| Batch API (accumulator) | GA |
| Token counting (с estimated_cost) | GA |
| Files API (upload, retrieve, delete) | beta |
| Sessions (multi-turn) | GA |
| Session compaction | beta |
| Context management / editing | beta |
| Session memory files | beta |
| Adaptive thinking (low/medium/high/max) | GA |
| Manual thinking budget | GA |
| Vision (image base64/url/file_id) | GA |
| PDF documents | GA |
| Citations | GA |
| Search result blocks (RAG) | GA |
| Server-side tool: web_search | GA |
| Server-side tool: web_fetch | GA |
| Server-side tool: code_execution | GA |
| Server-side tool: text_editor | GA |
| Server-side tool: bash | GA |
| Server-side tool: computer_use | beta |
| Custom tools (strict, defer_loading, allowed_callers) | GA |
| Structured outputs (output_config) | GA |
| MCP connector | beta |
| Skills (pre-built: xlsx, docx, pptx, pdf) | beta |
| Inference geo (US) | GA |
| Service tier (auto / standard_only) | GA |
| Fast mode | beta |
| Extended output (300K batch) | beta |
| Prefill (sonnet/haiku) | GA |

## API endpoints

| Метод | URL | Описание |
|-------|-----|----------|
| POST | `/api/v1/messages` | Sync + streaming (SSE) |
| POST | `/api/v1/messages/async` | Async webhook |
| POST | `/api/v1/messages/count_tokens` | Подсчет токенов |
| GET | `/api/v1/messages/{requestId}` | Получение результата async-запроса |
| POST | `/api/v1/messages/batch` | Batch accumulator (отправляет элементы в буфер) |
| POST | `/api/v1/batches` | Создание immediate batch |
| GET | `/api/v1/batches` | Список batch-запросов |
| GET | `/api/v1/batches/{batchId}` | Статус batch |
| GET | `/api/v1/batches/{batchId}/results` | Результаты batch |
| POST | `/api/v1/batches/{batchId}/cancel` | Отмена batch |
| DELETE | `/api/v1/batches/{batchId}` | Удаление batch |
| POST | `/api/v1/files` | Загрузка файла (multipart) |
| GET | `/api/v1/files` | Список файлов |
| GET | `/api/v1/files/{fileId}` | Метаданные файла |
| DELETE | `/api/v1/files/{fileId}` | Удаление файла |
| GET | `/api/v1/models` | Список доступных моделей |
| GET | `/api/v1/models/{alias}` | Информация о модели |
| POST | `/api/v1/sessions` | Создание сессии |
| GET | `/api/v1/sessions/{session}` | Информация о сессии |
| DELETE | `/api/v1/sessions/{session}` | Удаление сессии |
| GET | `/api/v1/sessions/{session}/messages` | История сообщений сессии |
| POST | `/api/v1/sessions/{session}/messages` | Отправка сообщения в сессию |
| POST | `/api/v1/skills` | Создание skill |
| GET | `/api/v1/skills` | Список skills |
| GET | `/api/v1/skills/{skillId}` | Информация о skill |
| DELETE | `/api/v1/skills/{skillId}` | Удаление skill |
| GET | `/api/v1/clients/me/usage` | Отчет по использованию текущего клиента |
| GET | `/internal/health` | Healthcheck (internal network) |
| GET | `/internal/stats` | Статистика (internal network) |

Все `/api/v1/*` эндпоинты защищены middleware `auth.api_key`.

## Очереди

Redis. 4 очереди: `high`, `default`, `low`, `batch`.

Jobs:
- `ProcessAsyncMessage` -- обработка async-запроса (timeout=600s)
- `DeliverWebhook` -- доставка webhook с retry (timeout=30s)
- `SubmitBatchToAnthropic` -- отправка batch в Anthropic API
- `FetchBatchResults` -- получение результатов batch
- `FlushBatchAccumulatorBucket` -- сброс накопленных batch-элементов

Scheduled jobs:
- `ClaudeApiPingScheduled` -- health-check ping к Anthropic API
- `RetryFailedWebhooks` -- повтор неудачных webhook-доставок
- `CleanupOrphanedFilesScheduled` -- удаление осиротевших файлов
- `FlushBatchAccumulatorScheduled` -- периодический flush accumulator
- `PollBatchesScheduled` -- опрос статуса активных batch

Ошибки не повторяются на уровне job -- отправляются через error webhook.

## БД

Таблицы (после drop legacy):
- `claude_workspaces` -- рабочие пространства Anthropic
- `clients` -- клиенты (API key hash, budget, allowed features, dev_mode, rate limits)
- `client_callback_urls` -- whitelist webhook URL per client
- `client_skills` -- skills, привязанные к клиенту
- `requests` -- лог запросов (аудит, cost, tokens, latency)
- `request_usage` -- детальное использование токенов per request
- `request_raw` -- сырые тела запросов/ответов (debug, TTL 14 дней)
- `async_pending` -- async-запросы в ожидании обработки (TTL 3 дня)
- `batches` -- batch-запросы
- `batch_items` -- элементы batch
- `files` -- загруженные файлы
- `sessions` -- multi-turn сессии (TTL 30 дней)
- `session_messages` -- сообщения в рамках сессии
- `session_memory_files` -- memory files, привязанные к сессиям
- `workspace_feature_usage` -- учет использования фич per workspace
- `failed_jobs` -- Laravel failed jobs (для `queue:retry`)

## Команды

| Команда | Описание |
|---------|----------|
| `client:create` | Создание клиента |
| `client:show` | Просмотр клиента |
| `client:rotate-key` | Ротация API-ключа |
| `client:rotate-secret` | Ротация signing secret |
| `client:enable-feature` | Включение feature для клиента |
| `client:disable-feature` | Отключение feature для клиента |
| `claude:status` | Статус подключения к Anthropic API |
| `claude:resume` | Возобновление приостановленных запросов |
| `claude:price-check` | Проверка актуальных цен |
| `claude:sync-capabilities` | Синхронизация capabilities моделей с API |
| `claude:cleanup-files` | Удаление осиротевших файлов |
| `claude:flush-accumulator` | Ручной flush batch accumulator |
| `claude:poll-batches` | Ручной опрос статуса batch |
| `requests:cleanup` | Очистка устаревших записей |
| `webhook:cleanup-expired-secrets` | Удаление истекших signing secrets |
| `llm:create-test-db` | Создание тестовой БД |

## Конфигурация

- `config/llm.php` -- model_aliases, model_capabilities, pricing, beta_headers, caching, batch, thinking, skills, timeouts, webhook, billing, dev_mode, queues
- `config/logging.php` -- канал `llm` (daily, error level, 30 дней)
- `.env` -- `ANTHROPIC_API_KEY`, `CLAUDE_ADMIN_API_KEY`, `API_KEY_PEPPER`, DB, Redis, queue, модели (`CLAUDE_OPUS_MODEL`, `CLAUDE_SONNET_MODEL`, `CLAUDE_HAIKU_MODEL`)

## Аутентификация и подпись webhooks

- Клиентский ключ: `gw_live_*`, передается как Bearer token. Хранится хэшированным (pepper + SHA-256).
- Webhook body -- wrapped envelope с HMAC-SHA256 подписью (signing secret).
- Grace period после ротации secret: 24 часа (оба ключа принимаются).

## Запуск

```bash
./start.sh    # Создает сеть, поднимает контейнеры, мигрирует БД
./stop.sh     # Останавливает контейнеры
```

Порты на хосте: 8080 (HTTP), 3307 (MySQL), 6381 (Redis).

## Тесты

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

Требуется БД `llm_gateway_test` на MySQL (порт 3307). Создать: `php artisan llm:create-test-db`.

## Документация

- [documentation/client_integration_guide.md](documentation/client_integration_guide.md) -- для интеграторов
- [documentation/internal_logic.md](documentation/internal_logic.md) -- для разработчиков
- [documentation/operational_runbook.md](documentation/operational_runbook.md) -- для on-call
- [documentation/microservices_setup_guide.md](documentation/microservices_setup_guide.md) -- deployment
