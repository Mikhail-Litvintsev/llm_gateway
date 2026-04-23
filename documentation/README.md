# LLM Gateway

Микросервис-шлюз для LLM-провайдеров. Принимает запросы в формате XML, преобразует в нативный API провайдера и доставляет результат через асинхронный callback.

## Технологии

- PHP 8.4, Laravel 13
- MySQL 8.4 (хранение данных, аудит)
- Redis 7 (очереди, кэш, rate limiting)
- Docker Compose (6 контейнеров: php-fpm, nginx, mysql, redis, queue worker, scheduler)

## Архитектурные принципы

- SOLID, DRY, KISS
- Паттерны: Strategy, Factory, DTO, Enum, Facade/Orchestrator
- Запрет на слово "Service" в именах классов
- Логика группируется в `app/Components/{Раздел}/` с подпапками `DTO/`, `Enums/`, `Contracts/`
- Главный класс каждого раздела — оркестратор-фасад в корне папки раздела
- Все ответы от LLM доставляются только через callback (асинхронно)
- Обработка через очереди (Laravel + Redis)

## Структура Components

```
app/Components/
  Auth/              — Аутентификация (API key, HMAC signing secret, key rotation)
  CallbackDelivery/  — Доставка callback с retry и подписью
  DevMode/           — Stub-ответы для тестирования без реальных API
  PromptAssembler/   — Сборка prompt в формат провайдера (Claude, OpenAI, Gemini)
  ProviderGateway/   — HTTP-вызовы к провайдерам, fallback, парсинг ответов
  RateLimiter/       — Rate limiting per API key
  RequestPipeline/   — Входной pipeline: XML-парсинг, валидация, сессии, idempotency
  Security/          — HMAC-SHA256 подпись callback-запросов
```

## Провайдеры

| Имя | Модель по умолчанию | Endpoint |
|-----|---------------------|----------|
| claude | claude-sonnet-4-6 | api.anthropic.com |
| openai | gpt-4o | api.openai.com |
| deepseek | deepseek-chat | api.deepseek.com |
| gemini | gemini-2.0-flash | generativelanguage.googleapis.com |
| mistral | mistral-large-latest | api.mistral.ai |

## API

| Метод | URL | Middleware | Описание |
|-------|-----|------------|----------|
| POST | `/api/v1/llm/request` | auth.api_key, rate.api_key | Прием XML-запроса |
| GET | `/internal/health` | internal.network | Healthcheck |
| GET | `/internal/stats` | internal.network | Статистика |

## Очереди

3 приоритета: `high`, `default`, `low`. Jobs:
- `ProcessLlmRequest` — обработка обычного запроса (tries=1, timeout=600s)
- `ProcessLlmStreamRequest` — обработка streaming запроса (tries=1, timeout=600s)
- `DeliverCallback` — доставка callback с retry (tries=1, timeout=30s)

Ошибки не повторяются на уровне job — отправляются через error callback.

## БД (8 таблиц)

- `api_clients` — клиенты с API key hash, rate limit, allowed providers, dev_mode
- `callback_urls` — whitelist callback URL per client
- `request_log` — лог всех запросов (аудит)
- `response_log` — лог ответов (токены, latency, provider)
- `raw_responses` — сырые ответы провайдеров (debug)
- `pending_prompts` — промпты в обработке (TTL 3 дня)
- `pending_responses` — ответы ожидающие доставки (TTL 3 дня)
- `session_history` — история multi-turn сессий

## Запуск

```bash
./start.sh    # Создает сеть, поднимает контейнеры, мигрирует БД
./stop.sh     # Останавливает контейнеры
```

Порты на хосте: 8080 (HTTP), 3307 (MySQL), 6381 (Redis).

## Команды проекта

См. [commands.md](documentation/commands.md).

## Тесты

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

Требуется БД `llm_gateway_test` на MySQL (порт 3307). Создать: `php artisan llm:create-test-database`.

## Конфигурация

- `config/llm.php` — провайдеры, очереди, callback, dev_mode, TTL
- `config/logging.php` — канал `llm` (daily, error level, 30 дней)
- `.env` — API ключи провайдеров, подключения к MySQL/Redis
