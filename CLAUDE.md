# LLM Gateway

Claude-only gateway v4. JSON-нативный API в формате Anthropic Messages (pass-through). Единственный провайдер -- Claude (Anthropic).

Режимы: sync, sync streaming (SSE), async webhook, batch (immediate + accumulator), sessions (multi-turn с compaction и context editing), files, server-side tools, MCP connector, skills.

## Технологии

- PHP 8.4, Laravel 13
- MySQL 8.4 (хранение данных, аудит, биллинг)
- Redis 7 (очереди, кэш, rate limiting)
- Docker Compose (6 сервисов: llm_gateway -- один php-fpm контейнер с двумя пулами (default `www` и `streaming`), llm_nginx, llm_mysql, llm_redis, llm_queue_worker, llm_scheduler)

## Архитектурные принципы

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
  Auth/            Authorization/    Billing/       Caching/
  Claude/          Delivery/         DevMode/       Healthcheck/
  Logging/         Pricing/          RateLimiting/  Routing/
  Sessions/        Skills/           Usage/         Validation/
```

## Качество кода

- **PHPStan: level 8** (максимальный), baseline не используется. Команда: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`. Все ошибки устраняются по существу; `ignoreErrors` допустимы только для структурных проблем в stub'ах сторонних библиотек и требуют комментария-обоснования в `phpstan.neon`.
- **Laravel Pint**: `vendor/bin/pint` (fix) / `vendor/bin/pint --test` (check). Конфигурация — Laravel default (`pint.json` отсутствует).
- **PHPUnit 12**: атрибуты `#[Test]`, `#[DataProvider]`; `@test`/`@dataProvider` phpdoc-теги не используются.
- **Strict typing**: `declare(strict_types=1);` — convention для нового кода в `app/`. Подавляющее большинство существующих файлов уже декларирует strict types.
- **CI**: `.github/workflows/ci.yml` прогоняет на каждом push: composer audit → pint → phpstan → migrations → tests.

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
