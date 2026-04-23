# Задача: контроль токенных лимитов Claude

## Проблема

Локальный rate limiter проверяет только RPM (requests per minute). Anthropic API лимитирует по трём осям: RPM, ITPM (input tokens per minute), OTPM (output tokens per minute). Для Tier 1 Claude Sonnet: RPM=50, ITPM=30,000, OTPM=8,000. Несколько запросов с большими промптами (6-10k токенов) исчерпывают ITPM за 3-5 запросов, задолго до RPM-лимита. Результат — массовые 429 и паузировка провайдера.

## Лимиты Claude API (Tier 1)

| Модель | RPM | ITPM | OTPM |
|--------|-----|------|------|
| Claude Sonnet 4.x | 50 | 30,000 | 8,000 |
| Claude Opus 4.x | 50 | 30,000 | 8,000 |
| Claude Haiku 4.5 | 50 | 50,000 | 10,000 |

Anthropic использует token bucket algorithm. Burst-трафик может вызвать 429 даже при формальном соблюдении лимита за минуту.

## Текущая архитектура (что есть)

- `RequestThrottle::attemptProvider()` — проверяет RPM через Laravel RateLimiter (Redis)
- `RawProviderResponse` — содержит `headers` из ответа провайдера (но они не используются)
- `ProviderRateLimitedException` — не содержит `retryAfter`
- `ResponseParser::mapProviderError()` — создаёт исключение при 429, но не парсит заголовки
- `ClaudeDriver::send()` — возвращает headers из ответа (строка 41)

## План реализации

### Шаг 1. Конфиг: добавить токенные лимиты Claude

**Файл**: `config/llm.php`

Только для провайдера `claude` добавить `token_limits`. Остальные провайдеры остаются без изменений — для них работает обычная RPM-система.

```php
'claude' => [
    // ...существующие поля...
    'rate_limit' => (int) env('CLAUDE_RATE_LIMIT_RPM', 45), // снизить с 50 до 45 (запас на burst)
    'token_limits' => [
        'input_tokens_per_minute'  => (int) env('CLAUDE_ITPM', 30000),
        'output_tokens_per_minute' => (int) env('CLAUDE_OTPM', 8000),
    ],
],
```

---

### Шаг 2. Оценка входных токенов из assembled payload

**Новый файл**: `app/Components/RateLimiter/Claude/ClaudeTokenEstimator.php`

Класс для оценки количества input-токенов до отправки запроса.

```php
class ClaudeTokenEstimator
{
    /**
     * Оценивает количество input-токенов в payload.
     * Используется для pre-flight проверки перед отправкой запроса к провайдеру.
     *
     * Алгоритм: считает общую длину текстового содержимого payload в символах,
     * делит на коэффициент (средняя длина токена в символах).
     *
     * Коэффициент 3.5 — среднее для английского текста и JSON-структур.
     * Для смешанного контента (английский + код + JSON) даёт погрешность ~15-20%.
     * Добавляем 25% запас для безопасности.
     */
    private const CHARS_PER_TOKEN = 3.5;
    private const SAFETY_MARGIN = 1.25;

    public function estimate(AssembledPayload $payload): int
    {
        $totalChars = 0;

        // system prompt
        $system = $payload->body['system'] ?? '';
        if (is_string($system)) {
            $totalChars += mb_strlen($system);
        } elseif (is_array($system)) {
            // Claude format: [{"type":"text","text":"..."}]
            foreach ($system as $block) {
                $totalChars += mb_strlen($block['text'] ?? '');
            }
        }

        // messages
        foreach ($payload->body['messages'] ?? [] as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $totalChars += mb_strlen($content);
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (is_string($block)) {
                        $totalChars += mb_strlen($block);
                    } else {
                        $type = $block['type'] ?? '';
                        // text blocks
                        $totalChars += mb_strlen($block['text'] ?? '');
                        // tool_result blocks — content is nested array/string
                        if ($type === 'tool_result' && isset($block['content'])) {
                            $totalChars += mb_strlen(json_encode($block['content'], JSON_UNESCAPED_UNICODE));
                        }
                    }
                }
            }
        }

        // tools definitions
        if (isset($payload->body['tools'])) {
            $totalChars += mb_strlen(json_encode($payload->body['tools'], JSON_UNESCAPED_UNICODE));
        }

        $estimated = (int) ceil($totalChars / self::CHARS_PER_TOKEN);

        return (int) ceil($estimated * self::SAFETY_MARGIN);
    }
}
```

---

### Шаг 3. Трекинг token budget через Redis

**Новый файл**: `app/Components/RateLimiter/Claude/ClaudeTokenBudget.php`

Отдельный класс для Claude-специфичной логики трекинга токенов. Не засоряет общий `RequestThrottle`.

Методы:

#### 3.1 `recordUsage()` — вызывается ПОСЛЕ успешного ответа Claude

Сохраняет в Redis данные о remaining tokens из заголовков ответа Anthropic.

```php
/**
 * Записывает актуальные remaining tokens из заголовков ответа Anthropic.
 * Вызывается после каждого успешного/неуспешного (кроме 429) ответа от Claude.
 */
public function recordUsage(array $responseHeaders): void
{
    // Anthropic headers: anthropic-ratelimit-input-tokens-remaining, anthropic-ratelimit-output-tokens-remaining
    // Заголовки приходят как массивы (Laravel HTTP client)

    $inputRemaining = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-input-tokens-remaining');
    $outputRemaining = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-output-tokens-remaining');
    $inputReset = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-input-tokens-reset');
    $outputReset = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-output-tokens-reset');

    if ($inputRemaining !== null) {
        Cache::put("token_budget:claude:input_remaining", (int) $inputRemaining, 120);
    }
    if ($outputRemaining !== null) {
        Cache::put("token_budget:claude:output_remaining", (int) $outputRemaining, 120);
    }
    if ($inputReset !== null) {
        Cache::put("token_budget:claude:input_reset", $inputReset, 120);
    }
    if ($outputReset !== null) {
        Cache::put("token_budget:claude:output_reset", $outputReset, 120);
    }
}

private function extractHeader(array $headers, string $name): ?string
{
    // HTTP headers в Laravel приходят как ['header-name' => ['value']]
    $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;
    if (is_array($value)) {
        return $value[0] ?? null;
    }
    return $value;
}
```

#### 3.2 `check()` — вызывается ПЕРЕД отправкой запроса к Claude

```php
/**
 * Проверяет, хватает ли token budget Claude для запроса.
 * Возвращает ThrottleResult.
 *
 * Логика:
 * - Если данных о remaining нет в Redis (первый запрос / данные истекли) — разрешаем.
 * - Если estimated input > input remaining — блокируем.
 * - Если output remaining < OUTPUT_SAFETY_THRESHOLD — блокируем (OTPM исчерпан).
 * - Иначе — разрешаем и атомарно уменьшаем input_remaining на estimated.
 *
 * Атомарный декремент предотвращает race condition при конкурентных запросах:
 * без него два параллельных запроса могли бы оба увидеть remaining=5000
 * и оба пройти проверку, хотя суммарно превышают лимит.
 */
private const OUTPUT_SAFETY_THRESHOLD = 500;

public function check(int $estimatedInputTokens): ThrottleResult
{
    $inputRemaining = Cache::get("token_budget:claude:input_remaining");

    // Нет данных — пропускаем (первый запрос или данные истекли)
    if ($inputRemaining === null) {
        return new ThrottleResult(
            allowed: true,
            limit: 0,
            remaining: 0,
            resetTimestamp: 0,
            retryAfter: null,
        );
    }

    // Проверка output budget: если output remaining ниже порога — блокируем
    $outputRemaining = Cache::get("token_budget:claude:output_remaining");
    if ($outputRemaining !== null && (int) $outputRemaining < self::OUTPUT_SAFETY_THRESHOLD) {
        $outputReset = Cache::get("token_budget:claude:output_reset");
        $retryAfter = $this->calculateRetryAfter($outputReset);

        return new ThrottleResult(
            allowed: false,
            limit: (int) config("llm.providers.claude.token_limits.output_tokens_per_minute", 0),
            remaining: (int) $outputRemaining,
            resetTimestamp: $outputReset ? strtotime($outputReset) : time() + 60,
            retryAfter: $retryAfter,
        );
    }

    if ($estimatedInputTokens > (int) $inputRemaining) {
        $inputReset = Cache::get("token_budget:claude:input_reset");
        $retryAfter = $this->calculateRetryAfter($inputReset);

        return new ThrottleResult(
            allowed: false,
            limit: (int) config("llm.providers.claude.token_limits.input_tokens_per_minute", 0),
            remaining: (int) $inputRemaining,
            resetTimestamp: $inputReset ? strtotime($inputReset) : time() + 60,
            retryAfter: $retryAfter,
        );
    }

    // Атомарно уменьшаем remaining, чтобы параллельные запросы видели актуальное значение
    Cache::decrement("token_budget:claude:input_remaining", $estimatedInputTokens);

    return new ThrottleResult(
        allowed: true,
        limit: (int) config("llm.providers.claude.token_limits.input_tokens_per_minute", 0),
        remaining: (int) $inputRemaining - $estimatedInputTokens,
        resetTimestamp: 0,
        retryAfter: null,
    );
}

private function calculateRetryAfter(?string $resetTimestamp): int
{
    if (!$resetTimestamp) {
        return 60; // fallback — 1 минута
    }
    $resetUnix = strtotime($resetTimestamp);
    $diff = $resetUnix - time();
    return max(1, $diff);
}
```

---

### Шаг 4. Парсить `retry-after` из 429 ответа

#### 4.1 **Файл**: `app/Components/ProviderGateway/Exceptions/ProviderRateLimitedException.php`

Добавить свойство `retryAfter`:

```php
class ProviderRateLimitedException extends ProviderException
{
    public function __construct(
        string $providerName,
        ?int $httpStatus = 429,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(
            errorCode: 'PROVIDER_RATE_LIMITED',
            message: "Provider '{$providerName}' returned rate limit (HTTP {$httpStatus}).",
            providerName: $providerName,
            httpStatus: $httpStatus,
        );
    }
}
```

#### 4.2 **Файл**: `app/Components/ProviderGateway/ResponseParser.php`

В методе `mapProviderError()` передать `retryAfter` при 429:

```php
if ($raw->isRateLimited()) {
    if ($this->isInsufficientQuota($raw)) {
        return new ProviderInsufficientFundsException($providerName, $raw->httpStatus);
    }

    $retryAfter = $this->parseRetryAfter($raw->headers);
    return new ProviderRateLimitedException($providerName, $raw->httpStatus, $retryAfter);
}
```

Добавить приватный метод:

```php
private function parseRetryAfter(array $headers): ?int
{
    $value = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;
    if (is_array($value)) {
        $value = $value[0] ?? null;
    }
    if ($value === null) {
        return null;
    }
    return (int) ceil((float) $value);
}
```

---

### Шаг 5. Интеграция в jobs

#### 5.1 **Файл**: `app/Jobs/ProcessLlmRequest.php`

**Внедрить** `ClaudeTokenEstimator` и `ClaudeTokenBudget` через DI в `handle()`:

```php
public function handle(
    XmlParser $xmlParser,
    PromptAssembler $promptAssembler,
    ProviderGateway $providerGateway,
    ResponseParser $responseParser,
    RequestThrottle $throttle,
    ClaudeTokenEstimator $claudeTokenEstimator,  // <-- добавить
    ClaudeTokenBudget $claudeTokenBudget,        // <-- добавить
): void {
```

**После сборки payload (после шага 4 — строка ~109), перед отправкой (шаг 5 — строка ~118)** — добавить проверку token budget **только для Claude**:

```php
// 4. Assemble payload for provider
$providerRequest = $promptAssembler->assemble($parsed, $resolvedProvider);

// 4.1 Check token budget BEFORE sending (Claude only)
if ($resolvedProvider->providerName === 'claude') {
    $estimatedTokens = $claudeTokenEstimator->estimate($providerRequest);
    $tokenBudget = $claudeTokenBudget->check($estimatedTokens);
    if (!$tokenBudget->allowed) {
        Log::channel('llm')->warning('provider.token_budget_exceeded', [
            'request_id' => $requestLog->request_id,
            'provider' => 'claude',
            'estimated_tokens' => $estimatedTokens,
            'remaining_tokens' => $tokenBudget->remaining,
            'retry_after' => $tokenBudget->retryAfter,
        ]);

        $this->release($tokenBudget->retryAfter ?? self::REQUEUE_DELAY);
        return;
    }
}
```

**После получения ответа (после шага 6 — сохранение raw response, строка ~130)** — записать token usage **только для Claude**:

```php
// 6. Save raw response
RawResponse::create([...]);

// 6.1 Record token usage from Anthropic response headers (Claude only)
if ($resolvedProvider->providerName === 'claude') {
    $claudeTokenBudget->recordUsage($rawResponse->headers);
}
```

**В `handleRetryableError()`** — использовать `retryAfter` из исключения.

Если есть `retryAfter` (rate limit с известным временем сброса) — ставим **временную** паузу вместо вечной.
Вечная пауза (`pauseProvider`) остаётся только для `insufficient_funds` и случаев без `retryAfter`.

```php
private function handleRetryableError(
    RequestLog $requestLog,
    ProviderRateLimitedException|ProviderInsufficientFundsException $e,
    RequestThrottle $throttle,
): void {
    $providerName = $e->providerName ?? $requestLog->provider_requested ?? 'unknown';
    $reason = $e instanceof ProviderInsufficientFundsException ? 'insufficient_funds' : 'rate_limit';

    $delay = ($e instanceof ProviderRateLimitedException && $e->retryAfter)
        ? $e->retryAfter
        : self::REQUEUE_DELAY;

    // Если retryAfter известен — временная пауза (auto-resume через $delay секунд).
    // Если нет (insufficient_funds, rate_limit без retryAfter) — вечная пауза до ручного resume.
    if ($e instanceof ProviderRateLimitedException && $e->retryAfter) {
        Log::channel('llm')->warning("provider.{$reason}_temporary_pause", [
            'request_id' => $requestLog->request_id,
            'provider' => $providerName,
            'error_code' => $e->errorCode,
            'retry_after' => $delay,
            'message' => $e->getMessage(),
        ]);

        // Временная пауза — Cache::put с TTL вместо Cache::forever
        Cache::put("provider_paused:{$providerName}", [
            'reason' => $reason,
            'paused_at' => now()->toIso8601String(),
            'auto_resume_at' => now()->addSeconds($delay)->toIso8601String(),
        ], $delay);
    } else {
        Log::channel('llm')->error("provider.{$reason}_paused", [
            'request_id' => $requestLog->request_id,
            'provider' => $providerName,
            'error_code' => $e->errorCode,
            'message' => $e->getMessage(),
            'action' => "Provider '{$providerName}' paused. Run: php artisan llm:resume-provider {$providerName}",
        ]);

        $throttle->pauseProvider($providerName, $reason);
    }

    $this->release($delay);
}
```

#### 5.2 **Файл**: `app/Jobs/ProcessLlmStreamRequest.php`

Идентичные изменения, все проверки только для Claude:
1. Добавить `ClaudeTokenEstimator` и `ClaudeTokenBudget` в `handle()`
2. Проверка token budget после assemble+stream модификации (после строки ~117, перед `sendStreaming` на строке ~121) — `if ($resolvedProvider->providerName === 'claude')`
3. Запись token usage из `$psrResponse->getHeaders()` после создания `$psrResponse` (строка ~121), до итерации стрима (строка ~144) — `if ($resolvedProvider->providerName === 'claude')`
4. Использование `$e->retryAfter` в `handleRetryableError()` — аналогично шагу 5.1 (временная пауза)
5. **Сохранять headers в `RawResponse`**: заменить `'response_headers' => []` на `'response_headers' => $psrResponse->getHeaders()` (строка ~178) — для отладки и консистентности с обычным job

Для streaming: заголовки rate limit доступны в PSR-7 response сразу (до чтения тела стрима).

---

### Шаг 6. Unit-тесты

**Новый файл**: `tests/Unit/Components/RateLimiter/Claude/ClaudeTokenEstimatorTest.php`

Тесты:
- `test_estimates_simple_text_message` — одно сообщение, проверить что оценка в пределах ±30% от ожидаемого
- `test_estimates_system_prompt_and_messages` — system + messages
- `test_estimates_with_tools` — payload с tools
- `test_estimates_array_content_blocks` — content как массив блоков (Claude format)
- `test_empty_payload_returns_zero` — пустой body

**Новый файл**: `tests/Unit/Components/RateLimiter/Claude/ClaudeTokenBudgetTest.php`

Тесты для `check()` и `recordUsage()`:
- `test_allows_when_no_budget_data` — нет данных в Redis → разрешить
- `test_blocks_when_estimated_exceeds_remaining` — estimated > remaining → заблокировать
- `test_allows_when_estimated_fits` — estimated <= remaining → разрешить
- `test_atomically_decrements_remaining_on_allow` — после разрешения remaining в Redis уменьшается на estimated
- `test_concurrent_checks_decrement_correctly` — два последовательных check() видят уменьшенный remaining
- `test_blocks_when_output_remaining_below_threshold` — output_remaining < 500 → заблокировать
- `test_allows_when_output_remaining_above_threshold` — output_remaining >= 500 → не блокирует по output
- `test_records_usage_from_headers` — проверить что remaining записывается в Redis
- `test_retry_after_calculated_from_reset` — проверить расчёт retryAfter

**Файл**: `tests/Unit/Components/ProviderGateway/ResponseParserTest.php`

Добавить:
- `test_rate_limited_exception_contains_retry_after` — проверить что retryAfter парсится из headers

---

## Порядок реализации

1. `config/llm.php` — добавить token_limits, снизить RPM
2. `ClaudeTokenEstimator` — новый класс + тесты
3. `ClaudeTokenBudget` — новый класс + тесты
4. `ProviderRateLimitedException` — добавить `retryAfter`
5. `ResponseParser` — парсинг `retry-after` + тест
6. `ProcessLlmRequest` — интеграция всех проверок
7. `ProcessLlmStreamRequest` — интеграция всех проверок
8. `LlmGatewayServiceProvider` — зарегистрировать `ClaudeTokenEstimator` и `ClaudeTokenBudget` если требуется (проверить — Laravel может auto-resolve через constructor injection)

## Верификация

1. `docker exec llm_gateway php artisan test --testsuite=Unit`
2. Проверка конфига: `docker exec llm_gateway php artisan tinker --execute="print_r(config('llm.providers.claude.token_limits'));"`
3. Интеграционный тест: отправить запрос с большим промптом (~10k символов), проверить что token budget записан в Redis после ответа
4. Тест блокировки: вручную записать `Cache::put('token_budget:claude:input_remaining', 100, 120)`, отправить запрос с промптом >100 токенов — должен быть release, а не отправка к провайдеру
