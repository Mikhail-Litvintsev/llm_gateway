# LLM Gateway v4 -- Внутренняя архитектура и логика

Документ предназначен для нового разработчика, вступающего в команду. Описывает все компоненты, потоки данных, конфигурацию и процедуры обновления.

---

## 1. Архитектура

Шлюз принимает запросы от клиентов, маршрутизирует их к Anthropic Messages API и возвращает результат одним из четырех способов.

### 1.1 Синхронный запрос (non-streaming)

```
Client
  |
  v
POST /api/v1/messages
  |
  v
ApiKeyAuth middleware  -->  Auth::authenticate()
  |
  v
ClaudeRateLimitTracker::canProceed()
  |
  v
MessageRequestValidator::validate(ctx=Sync)
  |
  v
AutoCacheInjector::inject()
  |
  v
PayloadBuilder::build()
  |
  v
Claude::sendMessage()  -->  HTTP POST api.anthropic.com/v1/messages
  |
  v
ResponseParser::parseSuccess() / ErrorMapper::map()
  |
  v
CostCalculator::calculate()
  |
  v
Billing::recordSpend()
  |
  v
Logging::record()
  |
  v
HTTP 200 JSON response --> Client
```

### 1.2 Синхронный streaming запрос

```
Client
  |
  v
POST /api/v1/messages  (stream: true)
  |
  v
ApiKeyAuth --> ClaudeRateLimitTracker --> MessageRequestValidator(ctx=SyncStream)
  |
  v
AutoCacheInjector --> PayloadBuilder::build()
  |
  v
Claude::streamMessage()
  |
  v
StreamResponder::stream()
  |                                     +-- StreamEventParser::consume()
  v                                     |   (inputTokens, outputTokens, stopReason)
SSE pass-through -->  Client            |
  |  (ignore_user_abort: upstream       |
  |   drain continues after disconnect) |
  v                                     v
message_stop / error              StreamAggregate
  |
  v
onComplete callback:
  CostCalculator::calculate()
  Billing::recordSpend()
  Logging::record()
  |
  v
HTTP response завершен
```

### 1.3 Асинхронный webhook запрос

```
Client
  |
  v
POST /api/v1/messages/async
  |
  v
ApiKeyAuth --> ClaudeRateLimitTracker --> MessageRequestValidator(ctx=AsyncCallback)
  |
  v
INSERT requests (status=accepted)
INSERT async_pending (payload_for_anthropic, callback_url)
  |
  v
HTTP 202 { request_id } --> Client
  |
  v
ProcessAsyncMessage job (queue: default, tries=1, timeout=600s)
  |
  v
Caching::autoInject() --> PayloadBuilder::build()
  |
  v
Claude::sendMessage() --> HTTP POST api.anthropic.com/v1/messages
  |
  v
ResponseParser --> CostCalculator --> Billing::recordSpend()
  |
  v
Logging::updateAsyncRecord()
  |
  v
DeliverWebhook job (queue: default, tries=1, timeout=30s)
  |
  v
Webhook::buildSignedRequest()  -->  Signer::sign()
  |                                  HMAC-SHA256(timestamp.body, secret)
  v
HTTP POST callback_url  (X-Webhook-Signature, X-Webhook-Timestamp)
  |
  v
success --> status=delivered
failure --> exponential backoff, до 10 попыток --> status=exhausted
```

### 1.4 Batch (аккумулятор)

```
Client (N запросов)
  |
  v
POST /api/v1/messages/batch  (item payload)
  |
  v
ApiKeyAuth --> MessageRequestValidator(ctx=BatchItem)
  |
  v
BatchAccumulator::append()
  |
  v
Redis Lua script (append_and_maybe_trigger.lua)
  - RPUSH item JSON в bucket list
  - SADD custom_id в ids set
  - HINCRBY bytes в meta hash
  - Проверка триггеров: count >= 100 / bytes >= 50MB / age >= 300s
  |
  v
trigger? --> FlushBatchAccumulatorBucket job (queue: batch)
  |
  v
flush_bucket.lua --> RPOP all items
  |
  v
BatchPayloadBuilder --> Anthropic POST /v1/messages/batches
  |
  v
BatchPersister --> INSERT batch_records, batch_items
  |
  v
PollBatchesScheduled (cron: everyMinute)
  |
  v
GET /v1/messages/batches/{id}  --> status check
  |
  v
ended? --> FetchBatchResults job
  |
  v
BatchResultParser --> BatchResultApplier --> CostCalculator
  |
  v
BatchWebhookFanout
  - <= 100 items: granular webhook per item
  - >  100 items: aggregated webhook
```

---

## 2. Components

Вся бизнес-логика группируется в `app/Components/{Раздел}/`. Главный класс каждого раздела -- оркестратор-фасад в корне папки.

### 2.1 Auth (`app/Components/Auth/`)

Аутентификация по API-ключу.

- **Auth** -- фасад. `authenticate(bearerToken)` извлекает `gw_live_*` ключ, хэширует через `KeyHasher`, ищет `Client` по `api_key_hash`.
- **ApiKeyAuth** -- Laravel middleware. Вызывает `Auth::authenticate()`, кладет `Client` в `$request->attributes`.
- **KeyHasher** -- SHA-256 хэширование с pepper из `config('llm.auth.api_key_pepper')`.
- **KeyGenerator** -- генерация новых API-ключей формата `gw_live_*`.
- **Exceptions/AuthenticationException** -- HTTP 401.

Зависимости: `Client` model.
Используется: middleware `auth.api_key` на всех `/api/v1/*` маршрутах.

### 2.2 Authorization (`app/Components/Authorization/`)

Авторизация: проверка модели, фичей, spend cap.

- **Authorization** -- фасад. `authorize(Client, modelAlias, featuresUsed)` возвращает `AuthorizationResult`.
- **DTO/AuthorizationResult** -- `allowed: bool`, `message`, `deniedFeature`.
- **Enums/AuthorizationDenialReason** -- `ModelNotAllowed`, `FeatureNotAllowed`, `MonthlySpendCapExceeded`.

Логика:
1. `checkModelWhitelist` -- `allowed_features['models']` (пустой массив = все разрешены).
2. `checkFeatureWhitelist` -- проверка каждой фичи, `prompt_caching` и `citations` разрешены по умолчанию.
3. `checkSpendCap` -- `current_month_spend_usd >= monthly_spend_cap_usd`.

Зависимости: `Client` model.
Используется: `Sessions::create()`, `Sessions::sendSync()`, `ProcessAsyncMessage`.

### 2.3 Billing (`app/Components/Billing/`)

Учет расходов клиента с мягким и жестким лимитом.

- **Billing** -- фасад. `preCheck(Client)` и `recordSpend(Client, costUsd)`.
- **UsageTracker** -- Redis-счетчик для hard cap enforcement. `reserve()`, `commit()`, `currentSpend()`. Ключ: `llm:billing:spend:{clientId}:{YYYY-MM}`, TTL до конца месяца + 1 час.
- **CostEstimator** -- оценка стоимости до вызова API (pre-flight).
- **DTO/SpendPreCheckResult** -- `SpendGateDecision` (AllowedUnlimited, AllowedWithinCap, SoftCapExceeded, HardCapExceeded).
- **DTO/SpendRecordResult** -- `newTotalUsd`, `remainingUsd`, `capJustExceeded`.
- **Exceptions/HardCapExceededException**.

Зависимости: `Client` model, Redis.
Используется: `Claude::sendMessage()`, `Claude::streamMessage()`, `ProcessAsyncMessage`.

### 2.4 Caching (`app/Components/Caching/`)

Автоматическая инъекция `cache_control` маркеров в payload для prompt caching.

- **Caching** -- фасад. `autoInject(payload, modelAlias, Client)`.
- **AutoCacheInjector** -- основная логика. Проверяет `auto_cache_injection` в `allowed_features`, считает символы в prefix (system + tools + messages кроме последнего), оценивает токены через `estimation_chars_per_token` (3.5), сравнивает с минимальным порогом модели.
- **DTO/CacheInjectionResult** -- `payload`, `outcome`, `estimatedPrefixTokens`.
- **Enums/CacheInjectionOutcome** -- `Injected`, `SkippedDisabled`, `SkippedAlreadyPresent`, `SkippedPrefixTooShort`, `SkippedCapExceeded`.

Минимальные пороги (токены): opus=1024, sonnet=1024, haiku=2048.
Для batch items: `injectForBatchItem()` с выбором TTL (1h для batch если `auto_use_1h_cache_for_batch`, иначе 5m).

Зависимости: `config('llm.claude.caching')`.
Используется: `ProcessAsyncMessage`, `MessagesController`.

### 2.5 Claude (`app/Components/Claude/`)

Центральный компонент: взаимодействие с Anthropic API.

- **Claude** -- главный фасад. `sendMessage()`, `streamMessage()`, `countTokens()`, `createBatch()`, `getBatch()`, `getBatchResults()`, `uploadFile()`, `deleteFile()`, `getFile()`, `listFiles()`.
- **ToolTypeCatalog** -- каталог всех типов серверных инструментов (web_search, web_fetch, code_execution, tool_search, memory, bash, text_editor, computer) с версионированными идентификаторами.

**Подразделы:**

**Payload/** -- сборка запроса к Anthropic:
- `PayloadBuilder` -- валидация и сборка payload, см. секцию 4.
- `FileSourceResolver` -- разрешение `file` source блоков через `FilesRepository`.
- `DTO/BuiltPayload` -- результат: `jsonBody`, `betaHeaders`, `modelSnapshot`, `modelAlias`, `serverToolTypes`.

**Response/** -- разбор ответа:
- `ResponseParser` -- парсинг content blocks, usage, citations, MCP tool uses, compaction detection. См. секцию 5.

**Errors/**:
- `ErrorMapper` -- маппинг HTTP-ошибок Anthropic в gateway-формат.

**Beta/**:
- `BetaHeaderRegistry` -- реестр feature -> beta header string. См. секцию 18.

**Batch/** -- batch-подсистема:
- `BatchAccumulator` (в `Accumulator/`) -- Redis аккумулятор с Lua скриптами.
- `BatchPayloadBuilder`, `BatchPreValidator`, `BatchPersister`.
- `BatchResultParser`, `BatchResultApplier`, `BatchResultsStreamer`.
- `BatchWebhookFanout`, `BatchCanceler`, `BatchCacheMetrics`.
- См. секцию 9.

**Files/** -- подсистема файлов:
- `FilesUploadHandler`, `FilesDeletionHandler`, `FilesRepository`.
- `FilesUsageIndex`, `FilesCleanupRunner`, `FileUploadValidator`.
- `FilePurpose` enum.
- См. секцию 10.

**DTO/**:
- `MessageRequest`, `MessageResponse`, `SendMessageInput`, `SendMessageOutput`.
- `UsageData`, `Batch`, `BatchCreateRequest`, `BatchItemInput`, `ResultLine`.
- `ClaudeFile`, `ModelInfo`, `StreamEvent`, `ThinkingSpec`, `TokenCountResult`.
- `ContextManagementConfig`, `McpServerConfig`.

**Enums/**:
- `BatchStatus`, `BatchItemStatus`, `ThinkingMode`, `ServerToolFeature`.

Зависимости: `Routing`, `Pricing`, `Billing`, `RateLimiting`, `Logging`, `Delivery`.
Используется: контроллерами, `ProcessAsyncMessage`, `Sessions`.

### 2.6 Delivery (`app/Components/Delivery/`)

Три подраздела доставки ответов.

**Stream/** -- SSE streaming:
- `StreamResponder` -- открывает SSE-соединение к Anthropic, транслирует поток клиенту, обрабатывает disconnect.
- `StreamEventParser` -- state machine для SSE событий (message_start, message_delta, message_stop, content_block_*, error, ping).
- `DTO/StreamAggregate` -- аккумулированные метрики: tokens, stopReason, serviceTier.
- `DTO/StreamContext` -- входные параметры для stream.
- `DTO/StreamOutcome` -- результат: cost, latency, disconnect status.
- `Enums/StreamEventType`.

**Sync/** -- синхронные ответы:
- `SyncResponder`.
- `DTO/AnthropicResponseEnvelope` -- `httpStatusCode`, `rawBody`, `anthropicHeaders`.
- `DTO/GatewayHeaders` -- X-Gateway-* заголовки.

**Webhook/** -- доставка webhook:
- `Webhook` -- фасад. `buildSignedRequest(Client, WebhookEnvelope)`.
- `Signer` -- HMAC-SHA256 подпись. Поддержка ротации секретов с grace period.
- `DTO/WebhookEnvelope` -- конверт с `requestId`, `event`, `anthropicResponse`, `error`, `billing`.
- `DTO/SignedRequest` -- body + headers (X-Webhook-Signature, X-Webhook-Timestamp).
- `Enums/WebhookEvent` -- `message.completed`, `message.failed`.
- `Exceptions/SecretUnavailableException`.

Зависимости: `Routing`, `Pricing`, `RateLimiting`.
Используется: `Claude::streamMessage()`, `DeliverWebhook` job.

### 2.7 DevMode (`app/Components/DevMode/`)

Stub-ответы для тестирования без реальных вызовов Anthropic API.

- **DevMode** -- фасад. `stub(MessageRequest, Client)`, `stubStream(MessageRequest, Client)`.
- **DevModeStubber** -- генерация фейковых ответов. Конфигурация: `latency_ms`, `content`, `simulate_cache_hit_rate`. Поддержка thinking блоков, web_search результатов, tool_use.
- **DTO/StubbedResponse** -- `body`, `headers`, `usage`.

Активация: флаг `dev_mode` на `Client` модели.
Конфиг: `config('llm.dev_mode')`.

### 2.8 Healthcheck (`app/Components/Healthcheck/`)

Мониторинг здоровья системы.

- **Healthcheck** -- фасад. `report()` возвращает `HealthReport`.
- **DTO/HealthReport** -- `overall`, `components` (db, redis, anthropic), `anthropicLastCheckAt`.
- **Enums/HealthStatus** -- `Ok`, `Degraded`, `Down`.

Проверки:
1. **db** -- `SELECT 1` с замером latency.
2. **redis** -- `PING` на connection `cache`.
3. **anthropic** -- кэшированный результат из Redis ключа `claude:healthcheck:anthropic`, записываемого `ClaudeApiPingScheduled` job.

Используется: `GET /internal/health`.

### 2.9 Logging (`app/Components/Logging/`)

Аудит-лог всех запросов.

- **Logging** -- фасад. `record(LoggingRecord)` -- атомарная запись в `requests`, `request_usage`, `request_raw` в одной транзакции. `updateAsyncRecord()` -- для async-потоков.
- **PayloadMasker** -- рекурсивная маскировка полей содержащих `oauth`, `token`, `secret`, `api_key`, `authorization`.
- **CapabilityDriftLogger** -- логирование дрейфа capabilities моделей.
- **DTO/LoggingRecord** -- все поля для трех таблиц.
- **DTO/LoggingResult** -- `requestId`.
- **Enums/Endpoint** -- `Messages`, ...
- **Enums/Mode** -- `Sync`, `SyncStream`, `AsyncCallback`, ...
- **Enums/RequestStatus** -- `Accepted`, `InProgress`, `Completed`, `CompletedDisconnected`, `FailedClientError`, `FailedServerError`, `FailedCallbackDelivery`.
- **Exceptions/IdempotencyException** -- при дубликате `request_id` (MySQL error 1062).

Зависимости: MySQL.
Используется: `Claude::sendMessage()`, `Claude::streamMessage()`, `ProcessAsyncMessage`.

### 2.10 Pricing (`app/Components/Pricing/`)

Расчет стоимости запросов.

- **Pricing** -- фасад. `calculate(UsageData, modelAlias, isBatched, geoUs)`.
- **CostCalculator** -- основной калькулятор. bcmath-арифметика с точностью 12 знаков. См. секцию 12.
- **ServerToolPricing** -- стоимость серверных инструментов (web_search per 1k, code_execution per hour).
- **CodeExecutionUsageTracker** -- учет бесплатных часов code_execution (1550 час/мес).
- **DTO/CostBreakdown** -- `inputCost`, `outputCost`, `cacheWrite5mCost`, `cacheWrite1hCost`, `cacheReadCost`, `serverToolWebSearchCost`, `serverToolCodeExecCost`, `geoMultiplierApplied`, `totalCost`.
- **DTO/Money** -- value object для денежных сумм (string-based bcmath).
- **DTO/CodeExecutionConsumption**.
- **Exceptions/UnknownPricingTierException**.

Зависимости: `config('llm.claude.pricing')`.
Используется: `Claude`, `StreamResponder`, `ProcessAsyncMessage`, `BatchResultApplier`.

### 2.11 RateLimiting (`app/Components/RateLimiting/`)

Локальный rate limiting на основе заголовков Anthropic.

- **ClaudeRateLimitTracker** -- `canProceed()` проверяет бюджет до вызова, `recordFromHeaders()` обновляет snapshot из ответа. Поддержка namespace-ов: Messages, BatchCreate, Priority, Fast.
- **RateLimitHeaderParser** -- парсинг `anthropic-ratelimit-*` заголовков.
- **RateLimitNamespace** -- enum: `Messages`, `BatchCreate`, `Priority`, `Fast`.
- **DTO/RateLimitSnapshot** -- `requestsLimit/Remaining/ResetAt`, `inputTokensLimit/Remaining/ResetAt`, `outputTokensLimit/Remaining/ResetAt`.
- **Exceptions/RateLimitExceededException** -- HTTP 429 с `Retry-After`.

Хранение: Redis, ключ `claude_rl:{namespace}:{workspaceKeyHash}:{modelSnapshot}`, TTL до reset + 5 секунд.
Safety margin: `config('llm.claude.rate_limit.safety_margin_pct')` (10%).

Зависимости: Redis.
Используется: `Claude::sendMessage()`, `Claude::countTokens()`, `StreamResponder`.

### 2.12 Routing (`app/Components/Routing/`)

Разрешение моделей и workspace.

- **Routing** -- фасад. `resolveModel(alias)`, `resolveWorkspace(Client)`.
- **ModelResolver** -- alias -> snapshot через `config('llm.claude.model_aliases')`. Кэширование в Laravel Cache на 1 час. `getCapabilities()` с опциональным live-fetch из Anthropic API.
- **ModelCapabilitiesFetcher** -- HTTP GET `/v1/models/{snapshot}` для live capabilities. Результат кэшируется в Redis ключ `claude:caps:{snapshot}` на 1 час.
- **WorkspaceResolver** -- клиент -> workspace -> decryptedApiKey. Модель `ClaudeWorkspace`. Также `resolveDefault()` для системных операций.
- **DTO/ResolvedModel** -- `alias`, `snapshot`, `capabilities`, `pricing`.
- **DTO/ResolvedWorkspace** -- `workspaceId`, `name`, `apiKey`, `anthropicWorkspaceId`.
- **DTO/ModelCapabilities**.
- **Exceptions/UnknownModelAliasException**, **WorkspaceNotConfiguredException**.

Зависимости: `ClaudeWorkspace` model, config.
Используется: `PayloadBuilder`, `Claude`, `BatchAccumulator`, `Sessions`.

### 2.13 Sessions (`app/Components/Sessions/`)

Multi-turn сессии с персистентной историей.

- **Sessions** -- фасад (реализует `SessionsContract`). `create()`, `sendSync()`, `sendStream()`, `getMetadata()`, `paginateHistory()`, `delete()`.
- **SessionStore** -- CRUD для сессий и сообщений. `appendUserMessage()`, `appendAssistantMessage()`, `loadFullHistory()`, `markCompacted()`, `decryptMcpTokens()`.
- **MemoryHandler** -- выполнение memory tool команд: view, create, str_replace, insert, delete, rename. Хранение файлов в `SessionMemoryFile`.
- **Memory/MemoryPathValidator** -- валидация путей `/memories/*`.
- **Contracts/SessionsContract**, **SessionStoreContract**.
- **DTO/** -- `SessionCreateInput`, `SessionMetadata`, `SessionHistoryPage`, `SessionSendMessageInput`, `SessionSendMessageResult`, `MemoryCommandResult`.
- **Enums/SessionStatus**, **MemoryCommand** (view, create, str_replace, insert, delete, rename).
- **Exceptions/** -- `SessionNotFoundException`, `SessionExpiredException`, `MemoryFileNotFoundException`, `MemoryFileExistsException`, `MemoryPathException`.

См. секцию 8.

Зависимости: `Claude`, `PayloadBuilder`, `ResponseParser`, `Authorization`, `Validation`, `WorkspaceResolver`, `ModelResolver`.
Используется: `SessionsController`.

### 2.14 Skills (`app/Components/Skills/`)

Управление skills (prebuilt инструментами для code_execution).

- **SkillsOrchestrator** -- фасад. `createPrebuilt()`, `listForClient()`, `findBySkillId()`, `delete()`.
- **EloquentSkillsRepository** -- реализация `SkillsRepository` через Eloquent (`ClientSkill` model).
- **Contracts/SkillsRepository**.
- **DTO/SkillDescriptor**.
- **Enums/PrebuiltSkill** -- `xlsx`, `docx`, `pptx`, `pdf`.

Требует `skills` в `allowed_features` клиента. Prebuilt skills определены в `config('llm.claude.skills.prebuilt')`.
Используется: `SkillsController`.

### 2.15 Usage (`app/Components/Usage/`)

Получение отчетов об использовании через Anthropic Usage API.

- **UsageReportOrchestrator** -- фасад. `getUsage(Client, queryParams)`.
- **UsageReportFetcher** -- HTTP GET к `config('llm.claude.endpoints.usage_report')`.
- **DTO/UsageReportRequest** -- `startingAt`, `endingAt`, `bucketWidth`, `workspaceId`, `limit`, `page`.

Требует `usage_api` в `allowed_features` и наличие `anthropic_workspace_id` у клиента.
Используется: `ClientUsageController`.

### 2.16 Validation (`app/Components/Validation/`)

Валидация входящих запросов.

- **Validation** -- фасад (если нужен).
- **MessageRequestValidator** -- основной валидатор. См. секцию 3.
- **ValidationContext** enum -- `Sync`, `SyncStream`, `AsyncCallback`, `BatchItem`, `Session`, `CountTokens`.
- **Rules/** -- семантические правила:
  - `ThinkingCompatibilityRule` -- thinking vs temperature/tool_choice.
  - `CitationsConsistencyRule` -- citations vs structured output.
  - `ServerFeaturesRule` -- проверка серверных фичей.
  - `SearchResultBlockRule` -- валидация search_result блоков.
  - `MemoryModelGateRule` -- memory tool только на поддерживаемых моделях.
  - `PtcContractRule` -- правила PTC (Parallel Tool Calling).
- **Schemas/** -- JSON Schema файлы: `message_request.json`, `batch_item.json`.
- **DTO/ValidationResult**, **ValidationError**.
- **Enums/ServiceTier** -- `standard_only`, `auto`.
- **Enums/Speed** -- `normal`, `fast`.
- **Exceptions/** -- `ValidationException`, `FeatureNotAllowedException`, `FeatureQuotaExhaustedException`.

Зависимости: `opis/json-schema`, config.
Используется: контроллерами, `Sessions`, `ProcessAsyncMessage`.

---

## 3. Validation pipeline

`MessageRequestValidator::validate()` проходит четыре этапа:

1. **preCheck** -- наличие `messages` и `model`, проверка alias в `config('llm.claude.model_aliases')`.

2. **schemaCheck** -- JSON Schema валидация через `opis/json-schema`. Схемы: `Schemas/message_request.json` (для sync/async/session/count_tokens) и `Schemas/batch_item.json` (для batch items).

3. **contextRulesCheck** -- правила зависящие от контекста вызова:
   - `forbid_stream` -- stream=true запрещен в batch_item и count_tokens.
   - `require_stream` -- stream=true обязателен в sync_stream.
   - `use_max_output_batch` -- max_tokens проверяется по `max_output_batch` capability.
   - Проверка `thinking.budget_tokens` vs `max_output`.
   - Opus prefill запрет (supports_prefill=false).

4. **phase4Rules** -- бизнес-правила:
   - `ServiceTier` -- валидация значения, проверка `priority_tier` в allowed_features для auto.
   - `inference_geo` -- значение из `config('llm.claude.inference_geo.allowed')`.
   - `Speed` -- fast mode: requires `fast_mode` feature, `supports_fast_mode` capability, несовместим с batch и priority.
   - `mcp_servers` -- requires `mcp_connector` feature.
   - `skills` -- requires `skills` feature + code_execution tool.
   - `MemoryModelGateRule` -- memory tool на совместимых моделях.
   - `SearchResultBlockRule` -- структура search_result блоков.
   - `CitationsConsistencyRule` -- citations vs structured output.
   - `ThinkingCompatibilityRule` -- thinking constraints.
   - `PtcContractRule` -- PTC constraints.

5. **semanticCheck** -- user message после tool_use должен содержать только tool_result блоки.

Добавление нового правила: создать класс в `Rules/` с методом `check(array $payload): ?ValidationError`, вызвать из `phase4Rules()`.

---

## 4. PayloadBuilder

`PayloadBuilder::build()` в `app/Components/Claude/Payload/PayloadBuilder.php` -- трансформация валидированного payload в формат Anthropic API.

Этапы:

1. **Разрешение модели** -- `ModelResolver::resolve(alias)` -> snapshot (например, `claude-sonnet-4-6`).

2. **Enforcement rules:**
   - `enforceMaxTokensCap` -- max_tokens <= model max_output.
   - `enforcePrefillCompatibility` -- last message role=assistant только если supports_prefill=true.
   - `validateThinking` -- adaptive (requires supports_adaptive_thinking, effort in low/medium/high), manual (budget_tokens > 0, < max_tokens на моделях без adaptive).
   - `validateSamplingWithThinking` -- top_p in [0.95, 1.0], tool_choice only auto/none.
   - `enforceCitationsVsStructuredOutput` -- взаимоисключающие.
   - `enforceServiceTierPermission` -- priority requires `priority_tier` feature.
   - `enforceInferenceGeo` -- geo override requires feature.

3. **Сборка payload** -- `assemblePayload()`: model -> snapshot, включение system, temperature (только без thinking), top_p, top_k, stop_sequences, tools, tool_choice, thinking, output_config, cache_control, service_tier, metadata, inference_geo, speed, skills, mcp_servers, stream.

4. **Нормализация messages** -- `normaliseMessageContent()`: search_result блоки проверяются на required keys (title, source, content).

5. **Разрешение file sources и vision** -- `resolveFileSourcesInMessages()`: source.type=file проходит через `FileSourceResolver` для подстановки Anthropic file_id по gateway file_id. Vision (image content blocks с base64/url/file_id source) обрабатывается нативно Anthropic API без трансформации.

6. **Нормализация tools** -- `normaliseTools()`: серверные инструменты (web_search, web_fetch, code_execution, tool_search, memory, bash, text_editor, computer) нормализуются отдельно. Custom tools проверяются на PTC (allowed_callers). Лимит custom tools: 128 (или 10000 с tool_search).

7. **Context management** -- compaction, clear_tool_uses, clear_thinking.

8. **Beta headers** -- `detectBetaFeatures()` + `collectBetaHeaders()`. Маппинг feature -> header через `betaHeaderMap` (инжектируется из `config('llm.claude.beta_headers')`).

9. **Сериализация** -- JSON encode, проверка 32MB лимита.

Результат: `BuiltPayload` с `jsonBody`, `betaHeaders`, `modelSnapshot`, `modelAlias`, `serverToolTypes`, `warnings`.

---

## 5. ResponseParser

`ResponseParser` в `app/Components/Claude/Response/ResponseParser.php`.

### parseMessageResponse()

Полный разбор ответа для sync-потока:

- **Content blocks** -- поддерживаемые типы:
  - `text` -- текстовый блок, может содержать `citations`.
  - `thinking` -- extended thinking, проверяется наличие `signature`.
  - `redacted_thinking` -- скрытый thinking.
  - `compaction` -- маркер компакции контекста.
  - `tool_use` -- вызов пользовательского инструмента.
  - `server_tool_use` -- вызов серверного и��струмента.
  - `document`, `search_result` -- документы и поисковые результаты.
  - `web_search_tool_result`, `web_fetch_tool_result`, `code_execution_tool_result` и другие *_tool_result.

- **MCP tagging** -- tool_use с `__` в имени получает `mcp_server_name`.
- **Compaction detection** -- по наличию compaction блока или непустого `usage.iterations`.
- **Memory tool uses** -- коллекция tool_use блоков с типом memory.
- **Citations** -- извлечение из text/document/search_result блоков.
- **Server tool use counts** -- из `usage.server_tool_use`.
- **Warnings** -- неизвестный stop_reason, неизвестный block type, thinking без signature.

### extractUsageData()

- `input_tokens`, `output_tokens`.
- `cache_creation_input_tokens_breakdown` -- разбивка по TTL (5m, 1h).
- `cache_read_input_tokens`.
- `thinking_tokens`.
- `server_tool_use` counts (web_search, web_fetch, code_execution, tool_search).
- `iterations` -- суммирование токенов из всех итераций (agentic loops).

### Stop reasons

Известные: `end_turn`, `max_tokens`, `tool_use`, `pause_turn`, `refusal`, `model_context_window_exceeded`.

---

## 6. Streaming

### StreamResponder

`app/Components/Delivery/Stream/StreamResponder.php` -- SSE pass-through.

Механизм:
1. `ignore_user_abort(true)` -- поток не прерывается при disconnect клиента.
2. HTTP POST к Anthropic с `stream: true` и `read_timeout` из конфига.
3. Побайтовое чтение из Guzzle PSR-7 body (chunk 8192 байт).
4. Если клиент подключен -- echo chunk + flush.
5. SSE events разделяются `\n\n`. Каждый event парсится `splitSseEvent()` на eventName + dataJson.
6. `StreamEventParser::consume()` аккумулирует tokens, stop_reason, errors.
7. Периодическая проверка disconnect: `connection_aborted()` каждые N events (`disconnect_check_interval`).
8. При disconnect -- продолжаем drain upstream до `message_stop` или `error`, чтобы корректно подсчитать usage.
9. По завершении -- `onComplete` callback с `StreamOutcome` для billing и logging.

### StreamEventParser

State machine, обрабатывает:
- `message_start` -- input_tokens, cache tokens, service_tier.
- `message_delta` -- output_tokens, stop_reason.
- `message_stop` -- completed=true.
- `error` -- errored=true, error type.
- `content_block_start/delta/stop`, `ping` -- игнорируются для billing.

### Error after 200

Anthropic может вернуть event `error` после HTTP 200 (streaming уже начался). `StreamEventParser` ловит это через `handleError()`, `StreamOutcome.errorType` = `stream_error`. Billing не выполняется если `!outcome.completed`.

### Заголовки ответа

```
Content-Type: text/event-stream
Cache-Control: no-cache, no-transform
Connection: keep-alive
X-Accel-Buffering: no
X-Gateway-Request-Id: {gatewayRequestId}
X-Gateway-Model-Alias: {modelAlias}
X-Gateway-Model-Snapshot: {modelSnapshot}
```

---

## 7. AutoCacheInjector

`app/Components/Caching/AutoCacheInjector.php`.

### Стратегия принятия решения

1. Проверка `auto_cache_injection` в `allowed_features` клиента -- если нет, skip.
2. Проверка наличия существующих `cache_control` маркеров -- если есть, skip.
3. Подсчет символов prefix: `system` + `tools` (JSON-сериализация) + все messages кроме последнего.
4. Оценка токенов: `ceil(chars / estimation_chars_per_token)`, default 3.5 chars/token.
5. Сравнение с минимальным порогом модели: opus=1024, sonnet=1024, haiku=2048 (`config('llm.claude.caching.minimum_prefix_tokens')`).
6. Проверка cap: `auto_cache_injection_max_breakpoints` (default 4).

### Инъекция

Для обычных запросов: добавление `cache_control: {type: ephemeral}` в корень payload.

Для batch items (`injectForBatchItem()`):
- TTL выбирается: `1h` если `auto_use_1h_cache_for_batch` включен, иначе `5m`.
- `cache_control` добавляется на последний блок `system`.

### Подсчет prefix

- `countCharsInSystem()` -- текстовые блоки system prompt.
- `countCharsInTools()` -- JSON-сериализация всего массива tools.
- `countCharsInMessagesExceptLast()` -- все messages кроме пос��еднего (prefix стабилен между запросами).

---

## 8. Sessions

`app/Components/Sessions/Sessions.php`.

### Модель данных

- Таблица `sessions` -- `session_id` (public), `client_id`, `model_alias`, `system`, `tools`, `mcp_servers` (encrypted tokens), `context_management`, `auto_resume`, `expires_at`, soft deletes.
- Таблица `session_messages` -- `session_id`, `role`, `content` (JSON), `stop_reason`, `usage` (JSON), `model`.
- Таблица `session_memory_files` -- `session_id`, `path`, `content`.

### Жизненный цикл

1. **create** -- валидация модели, авторизация (модель + фичи), проверка MCP connector feature, установка `expires_at` (default: `sessions.default_ttl_hours`).
2. **sendSync** -- загрузка full history, append user message, build payload, validate, call Claude, append assistant message, detect compaction.
3. **Memory tool loop** -- до `memory_tool_max_iterations` (default 5) итераций: если ответ содержит memory tool_use, выполнить команду через `MemoryHandler`, добавить tool_result, повторить запрос.
4. **Auto-resume** -- е��ли `auto_resume=true` и stop_reason=`pause_turn`, до `pause_turn_max_iterations` (default 5) повторных вызовов.
5. **sendStream** -- streaming с persist в finally-блоке: `appendAssistantMessage`, `handleCompaction`, memory tool dispatch.
6. **delete** -- soft delete сессии.

### Compaction

При обнаружении `compaction` блока в ответе -- `SessionStore::markCompacted()`.

### Memory tool

`MemoryHandler` поддерживает команды:
- `view` -- просмотр файла (с line range) или директории.
- `create` -- создание/перезапись файла.
- `str_replace` -- замена строки (ровно одно вхождение).
- `insert` -- вставка текста по номеру строки.
- `delete` -- удаление файла или директории.
- `rename` -- переименование файла.

Пути валидируются `MemoryPathValidator`, все операции в транзакциях.

---

## 9. Batch-подсистема

### Аккумулятор

`app/Components/Claude/Batch/Accumulator/BatchAccumulator.php`.

Паттерн: клиент отправляет items по одному через `POST /api/v1/messages/batch`, они накапливаются в Redis, и при достижении триггера (count/bytes/time) пакет отправляется в Anthropic Batch API.

**Redis-структура (per bucket):**
- Bucket key: `{clientId}:{modelAlias}` -> Redis list (items JSON).
- Meta key: `{bucket}:meta` -> Redis hash (callback_url, created_at, total_bytes).
- IDs key: `{bucket}:ids` -> Redis set (custom_id для дедупликации).
- Pending set: глобальный set всех активных bucket keys.

**Lua скрипты:**
- `append_and_maybe_trigger.lua` -- атомарный append + проверка триггеров.
- `flush_bucket.lua` -- атомарный drain всех items.

**Триггеры** (`FlushTriggerEvaluator`):
- `trigger_count` = 100 items.
- `trigger_bytes` = 50 MB.
- `trigger_seconds` = 300 секунд (проверяется через `FlushBatchAccumulatorScheduled` cron).

При триггере -- dispatch `FlushBatchAccumulatorBucket` job на queue `batch`.

### Жизненный цикл batch

1. `BatchAccumulator::append()` -- добавление item.
2. Flush -> `BatchPayloadBuilder` -> HTTP POST `/v1/messages/batches`.
3. `BatchPersister` -- INSERT в `batch_records` и `batch_items`.
4. `PollBatchesScheduled` (cron everyMinute) -- GET status у Anthropic.
5. Статус `ended` -> `FetchBatchResults` job -> `BatchResultParser` -> `BatchResultApplier`.
6. `BatchWebhookFanout` -- webhook доставка:
   - <= 100 items: per-item granular webhook.
   - > 100 items: aggregated webhook со ссылкой на results endpoint.

### Cache для batch

`config('llm.claude.batch.auto_use_1h_cache_for_batch')` = true -> system prompt получает `cache_control` с TTL `1h` (дешевле cache_write_1h, но дольше живет).

---

## 10. Files-подсистема

`app/Components/Claude/Files/`.

### Компоненты

- **FilesUploadHandler** -- загрузка файлов через Anthropic Files API (`POST /v1/files`). Валидация через `FileUploadValidator`.
- **FilesDeletionHandler** -- soft delete + DELETE в Anthropic.
- **FilesRepository** -- Eloquent-запросы к `file_records` таблице. Cursor-based пагинация.
- **FilesUsageIndex** -- индекс использования файлов в запросах.
- **FilesCleanupRunner** -- очистка неиспользуемых файлов.
- **FilePurpose** enum.
- **DTO/FileListPage** -- cursor-based page.

### Ownership

Каждый файл привязан к `client_id`. `FileSourceResolver` в PayloadBuilder проверяет ownership при разрешении `file` source блоков.

### Cleanup

`FilesCleanupRunner` запускается по расписанию (`claude:cleanup-files`, weekly). Удаляет файлы:
- `hard_delete_grace_days` = 14 дней после soft delete.
- `unused_alert_days` = 90 дней без использования.

---

## 11. Rate limiting

`app/Components/RateLimiting/Claude/ClaudeRateLimitTracker.php`.

### Механизм

Шлюз не реализует собственный rate limiter -- он отслеживает бюджет Anthropic API и предотвращает отправку запросов которые заведомо будут отклонены.

1. **recordFromHeaders** -- после каждого ответа Anthropic парсит заголовки `anthropic-ratelimit-*` и сохраняет snapshot в Redis.
2. **canProceed** -- до отправки запроса проверяет:
   - `requests` remaining >= 1.
   - `input_tokens` remaining >= estimatedInputTokens - expectedCacheReadTokens.
   - `output_tokens` remaining >= estimatedOutputTokens.
   - Safety margin: effective limit = remaining * (100 - 10%) / 100.
   - Если reset_at уже прошел -- пропускаем проверку (лимит сброшен).

### Namespaces

- `Messages` -- основные лимиты: requests, tokens, input_tokens, output_tokens.
- `BatchCreate` -- лимиты на создание батчей (отдельные заголовки `anthropic-ratelimit-batches-*`).
- `Priority` -- priority tier лимиты (`anthropic-priority-ratelimit-*`).
- `Fast` -- fast mode лимиты (`anthropic-fast-ratelimit-*`).

### Cache-aware ITPM

Эффективный input = `estimatedInputTokens - expectedCacheReadTokens`. Cached tokens не считаются к input token лимиту.

---

## 12. Cost calculation

`app/Components/Pricing/CostCalculator.php`.

### Формула

```
inputCost  = input_tokens  * (batch? batch_input : input) / 1_000_000
outputCost = output_tokens * (batch? batch_output : output) / 1_000_000

cacheWrite5mCost = cache_creation_5m_tokens * cache_write_5m / 1_000_000
cacheWrite1hCost = cache_creation_1h_tokens * cache_write_1h / 1_000_000
cacheReadCost    = cache_read_tokens * cache_read / 1_000_000

webSearchCost = web_search_count * web_search_per_1k / 1000

adjustedInput  = inputCost  * geoMultiplier * priorityMultiplier * fastMultiplier
adjustedOutput = outputCost * geoMultiplier * priorityMultiplier * fastMultiplier

total = adjustedInput + adjustedOutput + cacheWrite5mCost + cacheWrite1hCost
      + cacheReadCost + webSearchCost + codeExecCost
```

### Тарифы (USD per 1M tokens)

| Модель | input | output | cache_write_5m | cache_write_1h | cache_read | batch_input | batch_output |
|--------|-------|--------|----------------|----------------|------------|-------------|--------------|
| claude-opus | 5.00 | 25.00 | 6.25 | 10.00 | 0.50 | 2.50 | 12.50 |
| claude-sonnet | 3.00 | 15.00 | 3.75 | 6.00 | 0.30 | 1.50 | 7.50 |
| claude-haiku | 1.00 | 5.00 | 1.25 | 2.00 | 0.10 | 0.50 | 2.50 |

### Множители

- **Batch** -- batch_input/batch_output вместо input/output (в 2 раза дешевле).
- **Inference geo US** -- `config('llm.claude.inference_geo.multiplier')` = 1.10 (x1.1 к input и output).
- **Priority** -- `config('llm.claude.service_tier.priority_multiplier')` = 1.0 (применяется при service_tier=priority).
- **Fast mode** -- `config('llm.claude.pricing.fast_multiplier')` = 6.0 (x6 к input и output).

### Server tools

- **web_search** -- $10.00 per 1000 запросов.
- **web_fetch** -- $0.00.
- **code_execution** -- 1550 бесплатных часов/месяц, затем $0.05/час. Бесплатно если в запросе одновременно web_search или web_fetch (`ToolTypeCatalog::codeExecutionIsFree()`).

### Iterations

Если ответ содержит `usage.iterations` (agentic loops), токены всех итераций суммируются в `totalInputTokens`, `totalOutputTokens`, `totalCacheCreationTokens`, `totalCacheReadTokens`.

### Арифметика

Используется `bcmath` с точностью 12 знаков. Класс `Money` инкапсулирует операции add/multiply.

---

## 13. Webhook delivery

`app/Jobs/DeliverWebhook.php` + `app/Components/Delivery/Webhook/`.

### Процесс

1. Чтение `async_pending` и `requests` записей.
2. Сборка `WebhookEnvelope` -- конверт содержит:
   - `requestId`, `event` (message.completed / message.failed).
   - `anthropicResponse` -- полный ответ Anthropic (при success).
   - `error` -- type + message (при failure).
   - `billing` -- cost_usd, cost_breakdown, monthly_spend_after_usd, monthly_spend_remaining_usd.
3. `Signer::sign()` -- HMAC-SHA256:
   - Payload: `{timestamp}.{body}`.
   - Secret: `signing_secret_current_encrypted` (расшифровывается через `Crypt::decryptString`).
   - Результат: `sha256={hex}`.

### Retry schedule

Exponential backoff: `initial_delay_seconds * 2^(attempt-1)`, cap `max_delay_seconds`.
- Default: 10s, 20s, 40s, 80s, 160s, 320s, 640s, 1280s, 2560s, 3600s (cap).
- Max attempts: `config('llm.webhook.default_max_attempts')` = 10 (или `webhook_max_attempts` из allowed_features).

### Статусы

- `processing` -- ожидает retry.
- `delivered` -- успешно доставлен.
- `exhausted` -- все попытки исчерпаны. `requests.status` -> `failed_callback_delivery`.

### HMAC rotation grace

`Signer::verify()` проверяет подпись сначала текущим секретом, затем предыдущим (если в пределах grace period). Grace period: `config('llm.webhook.grace_period_seconds')` = 86400 (24 часа).

### Idempotency

Заголовок `X-Webhook-Request-Id` в каждом webhook позволяет клиенту дедуплицировать доставки.

---

## 14. Dev mode

`app/Components/DevMode/`.

### Механизм

Если у клиента установлен флаг `dev_mode` -- запросы не отправляются в Anthropic, вместо этого генерируются stub-ответы.

### DevModeStubber

- **Sync** -- `buildMessageResponse()`: формирует полный Anthropic-подобный JSON с:
  - `id` = `msg_stub_{random}`.
  - `content` -- text блок с `config('llm.dev_mode.content')`. При наличии thinking в запросе добавляет thinking блок. При наличии web_search tool добавляет server_tool_use + web_search_tool_result. При наличии tools без web_search -- tool_use блок.
  - `usage` -- оценка: input_tokens = ceil(messages_json_length / 4), output_tokens = ceil(content_length / 4).
  - Симуляция cache hit: `simulate_cache_hit_rate` = 0.5, детерминистично на основе CRC32(modelAlias + messages).

- **Stream** -- `buildStreamEvents()`: Generator SSE событий (message_start, content_block_start, chunk deltas с задержкой, content_block_stop, message_delta, message_stop). Latency: `config('llm.dev_mode.latency_ms')` = 150ms, распределенная между chunks.

### Headers

Stub ответы содержат `x-gateway-dev-mode: true` и фейковые rate limit заголовки.

### Активация

Per-client: поле `dev_mode` в таблице `clients`. Нет глобального переключателя.

---

## 15. Healthcheck и monitoring

### GET /internal/health

`MonitoringController::health()` -> `Healthcheck::report()`.

Проверки:
1. **db** -- `SELECT 1`. Status: Ok (с latency) или Down (с error).
2. **redis** -- `PING` на connection cache. Status: Ok или Down.
3. **anthropic** -- кэшированный результат пинга из Redis (`claude:healthcheck:anthropic`). Записывается `ClaudeApiPingScheduled` (cron: everyMinute).

HTTP status: 200 при Ok/Degraded, 503 при Down.

Формат ответа:
```json
{
  "status": "ok|degraded|down",
  "components": {
    "db": {"status": "ok", "latency_ms": 2, "error": null},
    "redis": {"status": "ok", "latency_ms": 1, "error": null},
    "anthropic": {"status": "ok", "latency_ms": 150, "error": null}
  },
  "anthropic_last_check_at": "2026-04-12T10:00:00+00:00",
  "anthropic_last_status": "ok"
}
```

### GET /internal/stats

`MonitoringController::stats()`.

Метрики:
- **queues** -- размеры очередей: high, default, low.
- **async_pending_counts** -- группировка по status (processing, delivered, exhausted).
- **top_spenders_month** -- топ-5 клиентов по current_month_spend_usd.

---

## 16. Scheduler

Все задачи определены в `routes/console.php` и `bootstrap/app.php`.

| Задача | Частота | Описание |
|--------|---------|----------|
| `RetryFailedWebhooks` | everyMinute, onOneServer | Retry webhook-ов со статусом processing и next_attempt_at <= now |
| `ClaudeApiPingScheduled` | everyMinute, onOneServer, queue: low | Пинг Anthropic API, запись результата в Redis |
| `requests:cleanup` | daily 03:00, withoutOverlapping | Удаление старых request_raw записей (retention) |
| `webhook:cleanup-expired-secrets` | hourly, withoutOverlapping | Очистка просроченных previous signing secrets |
| `claude:sync-capabilities` | weekly Sunday 03:00, onOneServer | Синхронизация capabilities моделей с Anthropic API |
| `claude:poll-batches` | everyMinute, onOneServer, withoutOverlapping(5) | Опрос статуса незавершенных batch-ей |
| `claude:flush-accumulator` | everyMinute, onOneServer, withoutOverlapping(5) | Flush аккумуляторных buckets по таймеру |
| `claude:cleanup-files` | weekly Sunday 03:00, onOneServer, withoutOverlapping(30) | Очистка soft-deleted и неиспользуемых файлов |

---

## 17. Конфигурация

Файл: `config/llm.php`.

### Корневые ключи

| Ключ | Тип | Описание |
|------|-----|----------|
| `version` | string | Версия конфига, '4.0'. Используется в cache key для ModelResolver |
| `max_request_payload_mb` | int | Лимит размера запроса, 32 MB |
| `max_batch_payload_mb` | int | Лимит размера batch payload, 256 MB |
| `max_file_size_mb` | int | Лимит размера файла, 500 MB |
| `async_request_ttl_seconds` | int | TTL async записей, 3 дня |
| `session_default_ttl_days` | int | TTL сессий по умолчанию, 30 дней |
| `raw_log_retention_days` | int | Хранение request_raw, 14 дней |

### claude.*

| Ключ | Описание |
|------|----------|
| `claude.default_api_key` | env ANTHROPIC_API_KEY. Fallback API key |
| `claude.admin_api_key` | env CLAUDE_ADMIN_API_KEY. Для admin operations |
| `claude.anthropic_version` | Версия API: '2023-06-01' |
| `claude.endpoints.*` | URL-ы: messages, count_tokens, batches, files, models, usage_report |
| `claude.default_model_alias` | Alias по умолчанию: 'claude-sonnet' |
| `claude.model_aliases` | Маппинг alias -> snapshot. claude-opus -> claude-opus-4-6, claude-sonnet -> claude-sonnet-4-6, claude-haiku -> claude-haiku-4-5 |
| `claude.model_capabilities` | Per-alias capabilities: context_window, max_output, max_output_batch, supports_thinking, supports_adaptive_thinking, supports_compaction, supports_prefill, min_cache_tokens, supports_fast_mode |
| `claude.pricing` | Per-alias тарифы + fast_multiplier + server_tools |
| `claude.inference_geo` | allowed: ['us'], multiplier: 1.10 |
| `claude.beta_headers` | Feature -> header маппинг (см. секцию 18) |
| `claude.rate_limit` | enforce_locally: true, safety_margin_pct: 10 |
| `claude.caching` | auto_top_level_default, min_prefix_safety_margin_tokens, default_ttl, estimation_chars_per_token: 3.5, minimum_prefix_tokens per family |
| `claude.batch` | enabled, max_items: 100000, max_wait_seconds: 86400, auto_use_1h_cache_for_batch: true, accumulator triggers |
| `claude.thinking` | default_effort: 'medium' |
| `claude.skills` | prebuilt: ['xlsx', 'docx', 'pptx', 'pdf'] |
| `claude.service_tier` | default: 'standard_only', priority_multiplier: 1.0 |
| `claude.timeouts` | connect: 10s, request: 600s, streaming: 1800s |
| `claude.files` | hard_delete_grace_days: 14, unused_alert_days: 90 |
| `claude.count_tokens` | output_tokens_factor: 0.5 |

### queues

| Ключ | Значение | Назначение |
|------|----------|------------|
| `queues.high` | 'high' | Приоритетные задачи |
| `queues.normal` | 'default' | Обычные задачи |
| `queues.low` | 'low' | Фоновые задачи |
| `queues.batch` | 'batch' | Batch operations |

### dev_mode

| Ключ | Описание |
|------|----------|
| `dev_mode.latency_ms` | Искусственная задержка, 150ms |
| `dev_mode.content` | Текст stub-ответа |
| `dev_mode.simulate_cache_hit_rate` | Вероятность cache hit, 0.5 |

### webhook

| Ключ | Описание |
|------|----------|
| `webhook.grace_period_seconds` | Grace period ротации секрета, 86400 (24 часа) |
| `webhook.default_max_attempts` | Макс. попыток доставки, 10 |
| `webhook.backoff` | Стратегия: 'exponential' |
| `webhook.initial_delay_seconds` | Начальная задержка retry, 10s |
| `webhook.max_delay_seconds` | Макс. задержка retry, 3600s |
| `webhook.request_timeout_seconds` | Timeout HTTP-запроса к callback, 30s |
| `webhook.signing_algorithm` | Алгоритм подписи: 'sha256' |

### async

| Ключ | Описание |
|------|----------|
| `async.pending_ttl_days` | TTL pending записей, 3 дня |

### billing

| Ключ | Описание |
|------|----------|
| `billing.hard_cap.redis_key_prefix` | Префикс Redis ключей: 'llm:billing:spend:' |

### auth

| Ключ | Описание |
|------|----------|
| `auth.api_key_pepper` | env API_KEY_PEPPER. Pepper для хэширования API-ключей |

---

## 18. Beta headers registry

Файл: `config/llm.php` -> `claude.beta_headers`.
Класс: `app/Components/Claude/Beta/BetaHeaderRegistry.php`.

### Маппинг feature -> header

| Feature | Header value |
|---------|-------------|
| `files_api` | `files-api-2025-04-14` |
| `compaction` | `compact-2026-01-12` |
| `context_management` | `context-management-2025-06-27` |
| `output_300k` | `output-300k-2026-03-24` |
| `mcp_client` | `mcp-client-2025-11-20` |
| `fast_mode` | `fast-mode-2026-02-01` |
| `computer_use` | `computer-use-2025-01-24` |
| `skills` | `skills-2025-10-02` |

### Автоматическое определение beta features

`PayloadBuilder::detectBetaFeatures()` анализирует payload:
- `cache_control` в payload или messages -> prompt_caching.
- `thinking` -> extended_thinking.
- `supports_compaction` + system -> compaction.
- File content blocks -> files_api.
- `max_tokens > 64000` -> output_300k.
- `speed = fast` -> fast_mode.
- `skills` -> skills.
- `mcp_servers` -> mcp_client.
- Computer tool -> computer_use.

Дополнительно: memory tool -> context_management, computer tool -> computer_use (из `BetaHeaderRegistry::assembleFromPayload()`).

### Процедура обновления beta headers

1. Прочитать Anthropic changelog и release notes. Определить какие beta features стали GA (graduated) и какие новые beta headers появились.
2. Обновить `config/llm.php` -> `claude.beta_headers`: удалить graduated, добавить новые, обновить версии.
3. Если feature graduated -- удалить соответствующую логику из `PayloadBuilder::detectBetaFeatures()` (header больше не нужен).
4. Запустить тесты: `php artisan test --testsuite=Unit`.
5. Проверить в dev mode или staging: убедиться что запросы с новыми headers проходят успешно.
6. Deploy на production.

---

## 19. Pricing update procedure

Anthropic может изменить тарифы на модели в любой момент. Процедура обновления:

1. Мониторить страницу Anthropic pricing и changelog. При обнаружении изменений -- начать процедуру.
2. Обновить `config/llm.php` -> `claude.pricing`: изменить тарифы для затронутых моделей (input, output, cache_write_5m, cache_write_1h, cache_read, batch_input, batch_output). При добавлении новой модели -- добавить новый блок.
3. Обновить server_tools pricing если изменились тарифы на web_search, code_execution.
4. Обновить `fast_multiplier` если изменился.
5. Запустить тесты CostCalculator: `php artisan test --filter=CostCalculator`.
6. Обновить клиентскую документацию с новыми тарифами.
7. Уведомить клиентов об изменении тарифов (email/webhook), указать дату вступления в силу.
8. Deploy на production в указанную дату.

---

## 20. Model upgrade procedure

При выходе нового snapshot модели (например, `claude-sonnet-4-7` вместо `claude-sonnet-4-6`):

1. Добавить новый snapshot в `config/llm.php` -> `claude.model_aliases`:
   ```php
   'claude-sonnet' => env('CLAUDE_SONNET_MODEL', 'claude-sonnet-4-7'),
   ```

2. Обновить `claude.model_capabilities` если capabilities изменились (context_window, max_output, supports_* флаги).

3. Обновить `claude.pricing` если тарифы отличаются.

4. Запустить `php artisan test --testsuite=Unit` -- убедиться что PayloadBuilder, Validation, CostCalculator работают.

5. Очистить кэш ModelResolver: `php artisan cache:forget routing:model:v4.0:claude-sonnet` (или flush).

6. Запустить `claude:sync-capabilities` для проверки live capabilities нового snapshot.

7. Deploy на production. Env-переменная `CLAUDE_SONNET_MODEL` позволяет rollback без деплоя:
   ```
   CLAUDE_SONNET_MODEL=claude-sonnet-4-6
   ```

8. План отката: изменить env-переменную на предыдущий snapshot, перезапустить php-fpm.

---

## 21. Edge cases catalog

### Cache: minimum prefix size

`AutoCacheInjector` проверяет минимальный размер prefix перед инъекцией `cache_control`. Если estimated tokens < minimum (opus/sonnet: 1024, haiku: 2048), инъекция пропускается. Это предотвращает ошибки Anthropic API при слишком маленьком prefix.
Код: `AutoCacheInjector::inject()`, проверка `$estimatedTokens < $minimumTokens`.

### Cache: max breakpoints cap

`auto_cache_injection_max_breakpoints` (default 4) ограничивает количество cache_control маркеров. `AutoCacheInjector::countCacheControlMarkers()` считает существующие маркеры в system, messages, tools.

### Streaming: error after HTTP 200

Anthropic может вернуть SSE event `error` после успешного HTTP 200. `StreamEventParser::handleError()` устанавливает `errored=true`. `StreamResponder` проверяет `aggregate.errored` и в `StreamOutcome` ставит `errorType='stream_error'`. Billing не выполняется если `!outcome.completed`.
Код: `StreamEventParser::handleError()`, `StreamResponder` callback.

### Streaming: client disconnect drain

`StreamResponder` использует `ignore_user_abort(true)`. При disconnect клиента поток из Anthropic продолжает drain до `message_stop`, чтобы usage data была корректно подсчитана для billing. `connection_aborted()` проверяется каждые N events.
Код: `StreamResponder::stream()`, переменная `$disconnected`.

### Accumulator: flush race

`FlushBatchAccumulatorBucket` job может быть dispatched несколько раз для одного bucket (trigger + cron timer). `flush_bucket.lua` атомарно drain-ит bucket -- второй flush получит пустой результат.
Код: `flush_bucket.lua`, `FlushBatchAccumulatorBucket`.

### Accumulator: callback URL mismatch

Все items в одном bucket должны иметь одинаковый callback_url. `BatchAccumulator::ensureCallbackUrlConsistency()` проверяет Redis hash meta и бросает `CallbackUrlMismatchException` при несовпадении.
Код: `BatchAccumulator::ensureCallbackUrlConsistency()`.

### Compaction: partial failure

При обнаружении compaction блока в session response, `Sessions::handleCompaction()` вызывает `SessionStore::markCompacted()`. Если compaction произошла, но persist failed, сессия может содержать inconsistent state. Compaction detection выполняется и в streaming finally-блоке.
Код: `Sessions::handleCompaction()`, `Sessions::streamAndPersist()`.

### HMAC rotation grace window

При ротации signing secret, `Signer::verify()` проверяет подпись сначала текущим секретом, затем предыдущим (есл�� ротация произошла менее `grace_period_seconds` = 86400 назад). Это позволяет клиентам обновить secret без downtime.
Код: `Signer::verify()`, `Signer::isWithinGracePeriod()`.

### Opus prefill restriction

Claude Opus 4.6 не поддерживает assistant prefill (`supports_prefill: false`). `PayloadBuilder::enforcePrefillCompatibility()` и `MessageRequestValidator::contextRulesCheck()` оба проверяют это.
Код: `PayloadBuilder::enforcePrefillCompatibility()`.

### PTC incompatibilities

Parallel Tool Calling (allowed_callers) несовместим с `disable_parallel_tool_use` и `strict: true`.
Код: `PayloadBuilder::normaliseCustomToolWithPtc()`, проверки в `build()`.

### Memory tool iteration limit

Sessions: memory tool loop ограничен `memory_tool_max_iterations` (default 5). При достижении лимита добавляется warning `sessions.memory_tool_iteration_limit`.
Код: `Sessions::sendSync()`.

### Thinking + sampling constraints

Когда thinking включен: `top_p` должен быть >= 0.95, `tool_choice` только auto/none. Temperature игнорируется (`assemblePayload` не включает temperature при наличии thinking).
Код: `PayloadBuilder::validateSamplingWithThinking()`, `PayloadBuilder::assemblePayload()`.

---

## 22. Расхождения с Anthropic API

Gateway v4 не является прозрачным прокси. Основные отличия:

### Модели

Gateway принимает только aliases (`claude-opus`, `claude-sonnet`, `claude-haiku`), не snapshot-идентификаторы. `PayloadBuilder` подставляет snapshot из `config('llm.claude.model_aliases')`.

### Аутентификация

Вместо `x-api-key` с ключом Anthropic, клиенты используют `Authorization: Bearer gw_live_*` ключи. Gateway подставляет workspace-specific Anthropic key.

### Заголовки

Gateway добавляет собственные заголовки в ответы:
- `X-Gateway-Request-Id` -- уникальный ID запроса в gateway.
- `X-Gateway-Model-Alias` -- использованный alias.
- `X-Gateway-Model-Snapshot` -- фактический snapshot отправленный в Anthropic.
- `X-Gateway-Dev-Mode: true` -- в dev mode.

### Endpoints, отсутствующие в Anthropic API

- `POST /api/v1/messages/async` -- асинхронный запрос с доставкой результата через webhook.
- `POST /api/v1/sessions` -- создание multi-turn сессии с persistent history.
- `GET/POST/DELETE /api/v1/sessions/{id}/*` -- управление сессиями и сообщениями.
- `POST /api/v1/messages/batch` -- batch accumulator (item-by-item append с автоматическим flush). Anthropic Batch API принимает сразу весь batch.
- `POST /api/v1/skills` -- управление skills.
- `GET /api/v1/clients/me/usage` -- отчет по использованию.

### Webhook envelope

Результат async-запроса доставляется в gateway-конверте:
```json
{
  "request_id": "req_...",
  "event": "message.completed",
  "anthropic_request_id": "...",
  "model_alias": "claude-sonnet",
  "model_snapshot": "claude-sonnet-4-6",
  "anthropic_response": { ... },
  "error": null,
  "billing": {
    "cost_usd": 0.0045,
    "cost_breakdown": { ... },
    "monthly_spend_after_usd": 125.50,
    "monthly_spend_remaining_usd": 874.50
  }
}
```

Подпись: `X-Webhook-Signature: sha256={hmac}`, `X-Webhook-Timestamp`.

### Beta headers

Gateway автоматически определяет и подставляет `anthropic-beta` заголовки. Клиенту не нужно знать какие features требуют beta headers.

### Authorization и billing

Gateway реализует собственные слои авторизации (модели, фичи, spend cap) и billing (cost calculation, monthly spend tracking), отсутствующие в Anthropic API.

### Batch accumulator

В отличие от Anthropic Batch API (один POST с массивом items), gateway предлагает item-by-item append через `POST /api/v1/messages/batch`. Items накапливаются в Redis и автоматически flush-атся при достижении триггеров.
