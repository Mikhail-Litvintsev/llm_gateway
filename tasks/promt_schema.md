# ТЗ: Полноценная поддержка Structured Output (response_format json_schema)

> **Источник:** Рекомендация 1.1 из `ai_prediction/tasks/promt_rec.md` — "Сократить промт за счёт JSON Schema на уровне API провайдера".
>
> **Цель:** Обеспечить передачу JSON Schema как параметра API провайдера для всех поддерживаемых провайдеров (нативно), с валидацией на входе и fallback-эмуляцией для DeepSeek (не поддерживает `json_schema`).
>
> **Результат для клиента (ai_prediction):** Клиент передаёт JSON Schema в XML-запросе (опционально). Gateway передаёт схему провайдеру — либо через нативный механизм API, либо через эмуляцию с инъекцией схемы в system prompt. Валидация ответа LLM на соответствие схеме — ответственность клиента. Если клиент не передал `response_format`, параметр не отправляется провайдеру.

---

## 1. Текущее состояние (анализ реализации)

### 1.1. Что уже реализовано

| Компонент | Файл | Что сделано |
|-----------|------|-------------|
| DTO | `RequestPipeline/DTO/ResponseFormatConfig.php` | Поля `type`, `name`, `strict`, `schema` — полный набор |
| DTO | `RequestPipeline/DTO/GenerationParameters.php` | Поле `responseFormat: ?ResponseFormatConfig` |
| XML-парсинг | `RequestPipeline/XmlParser.php:243-261` | Парсинг `<response_format>` — и простой строки, и сложной структуры с `<type>`, `<name>`, `<strict>`, `<schema>` |
| OpenAI маппинг | `PromptAssembler/ParameterMapper.php:78-81, 124-138` | Метод `mapOpenAiResponseFormat()` — корректно формирует `response_format` для OpenAI API с `json_schema.name`, `json_schema.strict`, `json_schema.schema` |
| Callback поле | `ProviderGateway/DTO/ProviderResponse.php:14` | Поле `structuredOutputFallback: bool` — передаётся в callback |
| Спецификация | `documentation/specification_llm_service.md` §10.2 | Описан формат XML, поведение fallback, флаг `structured_output_fallback` |

### 1.2. Что НЕ реализовано (список задач)

| # | Проблема | Где | Влияние |
|---|----------|-----|---------|
| 1 | **Claude: structured output полностью игнорируется** | `ParameterMapper.php:19-56` (`mapClaude`) | Claude поддерживает structured output нативно через `output_config.format`, но маппинг отсутствует |
| 2 | **Gemini: response_format полностью игнорируется** | `ParameterMapper.php:93-122` (`mapGemini`) | Аналогично — Gemini поддерживает `responseMimeType` + `responseSchema`, но маппинг отсутствует |
| 3 | **DeepSeek: маппится через OpenAI, но не поддерживает json_schema** | `ParameterMapper.php:58-91` | DeepSeek поддерживает только `json_object`, но не `json_schema` с strict mode. Mistral поддерживает `json_schema` аналогично OpenAI — корректно обрабатывается текущим кодом. При передаче `json_schema` в DeepSeek — ошибка от провайдера |
| 4 | **Fallback-эмуляция не реализована** | Нигде | Спецификация §10.2 требует: если провайдер не поддерживает structured output — инъекция schema в system prompt + флаг `structured_output_fallback: true`. Код всегда возвращает `false` |
| 5 | **Валидация response_format отсутствует** | `RequestValidator.php:273-301` (`validateParameters`) | Не проверяется: валидность JSON в `<schema>`, наличие `name` при `json_schema`, допустимость `type` |
| 6 | **`structured_output_fallback` всегда `false`** | `ResponseParser.php:71, 110, 148` | Хардкод `false` во всех трёх парсерах, не отражает реальное поведение |

---

## 2. Карта поддержки Structured Output по провайдерам

| Провайдер | `json_object` | `json_schema` (strict) | Нативный механизм API | Fallback-стратегия |
|-----------|:---:|:---:|---|---|
| **OpenAI** | Да | Да | `response_format: {type: "json_schema", json_schema: {name, strict, schema}}` | Не нужна |
| **Claude** | Да | Да | `output_config: {format: {type: "json_schema", schema: {...}}}` | Не нужна |
| **Gemini** | Да | Да | `generationConfig.responseMimeType: "application/json"` + `generationConfig.responseSchema: {...}` | Не нужна |
| **DeepSeek** | Да | Нет | `response_format: {type: "json_object"}` | При `json_schema` — эмуляция (schema в system prompt) |
| **Mistral** | Да | Да | `response_format: {type: "json_schema", json_schema: {name, strict, schema}}` — формат идентичен OpenAI | Не нужна |

---

## 3. Задачи реализации

### 3.1. Валидация `response_format` на входе

**Файл:** `app/Components/RequestPipeline/RequestValidator.php`

**Метод:** Добавить приватный метод `validateResponseFormat()`, вызываемый из `validateParameters()`.

**Правила валидации:**

1. **Допустимые значения `type`:** `text`, `json_object`, `json_schema`. Любое другое — ошибка `INVALID_PARAMETER` с сообщением `"response_format.type must be one of: text, json_object, json_schema."`.

2. **При `type = json_schema`:**
   - Поле `schema` обязательно — если отсутствует, ошибка `INVALID_PARAMETER`: `"response_format.schema is required when type is json_schema."`.
   - Содержимое `schema` должно быть валидным JSON — `json_decode()` не должен вернуть `null`. Ошибка: `"response_format.schema must be valid JSON."`.
   - Декодированный JSON должен быть объектом (ассоциативный массив) с ключом `type`. Ошибка: `"response_format.schema must be a JSON Schema object with 'type' property."`.
   - Поле `name` обязательно — если отсутствует, ошибка `INVALID_PARAMETER`: `"response_format.name is required when type is json_schema."`.
   - Поле `name` — строка, соответствующая `^[a-zA-Z_][a-zA-Z0-9_-]*$`, максимум 64 символа. Ошибка: `"response_format.name must match pattern ^[a-zA-Z_][a-zA-Z0-9_-]*$ and be at most 64 characters."`.

3. **При `type = text`** — поля `schema`, `name`, `strict` должны отсутствовать (игнорируются, но не ошибка).

4. **При `type = json_object`** — поле `schema` игнорируется (не передаётся провайдеру).

---

### 3.2. Enum для поддержки structured output провайдерами

**Файл (создать):** `app/Components/PromptAssembler/Enums/StructuredOutputSupport.php`

```php
enum StructuredOutputSupport: string
{
    case Native = 'native';       // Провайдер поддерживает json_schema нативно
    case JsonObjectOnly = 'json_object_only'; // Только json_object, без schema
    case None = 'none';           // Нет нативной поддержки, нужна эмуляция
}
```

**Файл (создать):** `app/Components/PromptAssembler/StructuredOutputResolver.php`

Класс определяет уровень поддержки structured output для конкретного провайдера:

```
resolveSupport(string $providerName): StructuredOutputSupport
```

Маппинг:
- `openai` → `Native`
- `claude` → `Native`
- `gemini` → `Native`
- `mistral` → `Native`
- `deepseek` → `JsonObjectOnly`

Метод `needsFallbackEmulation(string $providerName, ResponseFormatConfig $format): bool`:
- Возвращает `true`, если `format.type === 'json_schema'` и провайдер НЕ `Native`.
- Возвращает `true`, если `format.type === 'json_object'` и провайдер не `Native` и не `JsonObjectOnly`.
- Иначе `false`.

---

### 3.3. Маппинг response_format для Claude

**Файл:** `app/Components/PromptAssembler/ParameterMapper.php`

**Изменения в `mapClaude()`:**

Claude Messages API поддерживает structured output нативно (с ноября 2025), но использует **отличный от OpenAI** формат — параметр `output_config.format` вместо `response_format`.

**Формат API Anthropic:**
```json
{
    "model": "claude-sonnet-4-6",
    "max_tokens": 2048,
    "messages": [...],
    "output_config": {
        "format": {
            "type": "json_schema",
            "schema": {
                "type": "object",
                "properties": {
                    "action": {"type": "string", "enum": ["LONG", "SHORT", "NO_TRADE"]},
                    "confidence": {"type": "number"}
                },
                "required": ["action", "confidence"],
                "additionalProperties": false
            }
        }
    }
}
```

**Особенности Claude vs OpenAI:**
- Параметр называется `output_config.format`, а не `response_format`
- Нет поля `name` в schema definition (в отличие от OpenAI `json_schema.name`)
- Нет поля `strict` — Claude всегда использует strict mode при json_schema
- Поддерживает JSON Schema draft 2020-12
- Не поддерживает: `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `default`, `oneOf`, `anyOf`, `allOf`
- `additionalProperties: false` рекомендуется добавлять ко всем объектам

**Добавить в `mapClaude()`:**

```php
if ($params->responseFormat) {
    $formatConfig = $this->mapClaudeResponseFormat($params->responseFormat);
    if ($formatConfig !== null) {
        $mapped['output_config'] = $formatConfig;
    }
}
```

**Добавить приватный метод `mapClaudeResponseFormat()`:**

```php
private function mapClaudeResponseFormat(ResponseFormatConfig $format): ?array
{
    if ($format->type === 'json_schema' && $format->schema) {
        $schema = json_decode($format->schema, true) ?: [];
        return [
            'format' => [
                'type' => 'json_schema',
                'schema' => $schema,
            ],
        ];
    }

    // Claude не поддерживает json_object без schema — игнорируем
    return null;
}
```

**Примечание:** Claude не поддерживает `type: "json_object"` без schema (в отличие от OpenAI). При `json_object` без schema для Claude параметр не передаётся — провайдер использует свободный формат. Если клиенту нужен JSON от Claude без строгой schema, он должен использовать `json_schema` с минимальной schema `{"type": "object"}`.

---

### 3.4. Маппинг response_format для Gemini

**Файл:** `app/Components/PromptAssembler/ParameterMapper.php`

**Добавить в `mapGemini()`:**

```php
if ($params->responseFormat) {
    $mapped['generationConfig'] = array_merge(
        $mapped['generationConfig'] ?? [],
        $this->mapGeminiResponseFormat($params->responseFormat),
    );
}
```

**Добавить приватный метод `mapGeminiResponseFormat()`:**

```php
private function mapGeminiResponseFormat(ResponseFormatConfig $format): array
{
    if ($format->type === 'json_schema' && $format->schema) {
        $schema = json_decode($format->schema, true) ?: [];
        // Gemini не поддерживает additionalProperties — удалить рекурсивно
        $schema = $this->removeAdditionalProperties($schema);
        return [
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ];
    }

    if ($format->type === 'json_object') {
        return [
            'responseMimeType' => 'application/json',
        ];
    }

    return [];
}
```

**Добавить вспомогательный метод `removeAdditionalProperties()`:**

Рекурсивно обходит JSON Schema и удаляет ключ `additionalProperties`, т.к. Gemini API его не поддерживает и возвращает ошибку при его наличии.

```php
private function removeAdditionalProperties(array $schema): array
{
    unset($schema['additionalProperties']);

    if (isset($schema['properties'])) {
        foreach ($schema['properties'] as $key => $prop) {
            if (is_array($prop)) {
                $schema['properties'][$key] = $this->removeAdditionalProperties($prop);
            }
        }
    }

    if (isset($schema['items']) && is_array($schema['items'])) {
        $schema['items'] = $this->removeAdditionalProperties($schema['items']);
    }

    return $schema;
}
```

---

### 3.5. Маппинг response_format для DeepSeek

**Файл:** `app/Components/PromptAssembler/ParameterMapper.php`

**Изменения в `mapOpenAi()`:**

Текущий код вызывает `mapOpenAiResponseFormat()` для всех трёх провайдеров (`openai`, `deepseek`, `mistral`). Но DeepSeek не поддерживает `json_schema` с strict mode.

Изменить логику:

```php
if ($params->responseFormat) {
    if ($providerName === 'deepseek' && $params->responseFormat->type === 'json_schema') {
        // DeepSeek не поддерживает json_schema — будет использован fallback
        // Передаём только json_object, schema пойдёт через system prompt (§3.6)
        $mapped['response_format'] = ['type' => 'json_object'];
    } else {
        $mapped['response_format'] = $this->mapOpenAiResponseFormat($params->responseFormat);
    }
}
```

---

### 3.6. Fallback-эмуляция: инъекция schema в system prompt

**Файл (создать):** `app/Components/PromptAssembler/StructuredOutputFallback.php`

Класс отвечает за инъекцию JSON Schema в system prompt, когда провайдер не поддерживает structured output нативно.

**Публичный метод:**

```
injectSchemaIntoSystemPrompt(string $systemPrompt, ResponseFormatConfig $format): string
```

**Алгоритм:**

1. Декодировать `$format->schema` из JSON-строки в массив.
2. Сформировать текстовый блок-инструкцию:

```
RESPONSE FORMAT REQUIREMENT:
You MUST respond with a valid JSON object that strictly conforms to the following JSON Schema.
Do NOT include any text before or after the JSON. Output ONLY the JSON object.

Schema name: {$format->name}

JSON Schema:
{pretty-printed schema}
```

3. Если `$format->strict === true`, добавить:

```
STRICT MODE: No additional properties are allowed beyond those defined in the schema.
All required fields MUST be present. Enum values MUST match exactly.
```

4. Добавить этот блок в **конец** system prompt через `\n\n`.

5. Вернуть модифицированный system prompt.

**Где вызывать:** В `PromptAssembler::assemble()`, после шага 1 (BlockAssembler) и перед шагом 4 (format). Проверять через `StructuredOutputResolver::needsFallbackEmulation()`.

**Изменения в `PromptAssembler.php`:**

```php
public function assemble(ParsedRequest $parsed, ResolvedProvider $provider): AssembledPayload
{
    // 1. Assemble blocks into system prompt + messages
    $assembled = $this->blockAssembler->assemble($parsed->blocks, $provider->providerName);

    // 1.5 NEW: Structured output fallback injection
    $structuredOutputFallback = false;
    if ($parsed->parameters?->responseFormat) {
        $needsFallback = $this->structuredOutputResolver->needsFallbackEmulation(
            $provider->providerName,
            $parsed->parameters->responseFormat,
        );
        if ($needsFallback) {
            $assembled = $assembled->withSystemPrompt(
                $this->structuredOutputFallback->injectSchemaIntoSystemPrompt(
                    $assembled->systemPrompt,
                    $parsed->parameters->responseFormat,
                )
            );
            $structuredOutputFallback = true;
        }
    }

    // ... остальной код без изменений ...
}
```

Для передачи флага `$structuredOutputFallback` наружу — добавить его в `AssembledPayload` (см. §3.8).

---

### 3.7. Изменения в DTO

#### 3.8.1. `AssembledPayload` — добавить флаг fallback

**Файл:** `app/Components/PromptAssembler/DTO/AssembledPayload.php`

Добавить:
```php
public function __construct(
    public array $body,
    public array $headers,
    public bool $structuredOutputFallback = false, // NEW
) {}
```

#### 3.8.2. `AssembledMessages` — добавить метод withSystemPrompt

**Файл:** `app/Components/PromptAssembler/DTO/AssembledMessages.php`

Добавить метод:
```php
public function withSystemPrompt(string $systemPrompt): self
{
    return new self(
        systemPrompt: $systemPrompt,
        messages: $this->messages,
    );
}
```

---

### 3.8. Прокидывание флага `structuredOutputFallback` через pipeline

**Цепочка передачи:**

1. `StructuredOutputResolver::needsFallbackEmulation()` → определяет необходимость fallback
2. `PromptAssembler::assemble()` → устанавливает флаг в `AssembledPayload`
3. `ProcessLlmRequest::handle()` → читает флаг из `AssembledPayload`, прокидывает в `ProviderResponse`
4. `ResponseParser::parse()` — **убрать хардкод `false`**. Вместо этого принимать параметр `bool $structuredOutputFallback` и передавать в `ProviderResponse`.

**Изменения в `ResponseParser.php`:**

Сигнатура метода `parse()`:
```php
public function parse(
    RawProviderResponse $raw,
    ResolvedProvider $provider,
    bool $structuredOutputFallback = false, // NEW
): ProviderResponse
```

Во всех трёх методах `parseClaude()`, `parseOpenAiCompatible()`, `parseGemini()` — заменить `structuredOutputFallback: false` на `structuredOutputFallback: $structuredOutputFallback`.

**Изменения в `ProcessLlmRequest.php`:**

Шаг 8:
```php
$isFallback = $providerRequest->structuredOutputFallback;
$providerResponse = $responseParser->parse($rawResponse, $resolvedProvider, $isFallback);
```

---

### 3.9. Форматтеры — передача флага

**Файл:** `app/Components/PromptAssembler/Contracts/ProviderFormatterContract.php`

Сигнатура метода `format()` не меняется. Флаг `structuredOutputFallback` устанавливается в `AssembledPayload` вызывающим кодом (`PromptAssembler`), а не форматтером.

**Изменения в `ClaudeFormatter.php`, `OpenAiFormatter.php`, `GeminiFormatter.php`:**

Добавить приём параметра `structuredOutputFallback` в конструктор `AssembledPayload`:

```php
return new AssembledPayload(
    body: $body,
    headers: [...],
    structuredOutputFallback: false, // форматтер не знает о fallback
);
```

Затем `PromptAssembler::assemble()` переопределяет флаг:

```php
$payload = $formatter->format(...);
if ($structuredOutputFallback) {
    $payload = new AssembledPayload(
        body: $payload->body,
        headers: $payload->headers,
        structuredOutputFallback: true,
    );
}
return $payload;
```

---

## 4. Тестирование

### 4.1. Unit-тесты

| Файл | Тест | Что проверяет |
|------|------|---------------|
| `ParameterMapperTest.php` | `test_maps_openai_json_schema_response_format` | Корректный маппинг json_schema для OpenAI (уже работает, подтвердить) |
| `ParameterMapperTest.php` | `test_maps_gemini_json_schema_response_format` | **Новый.** responseMimeType + responseSchema в generationConfig |
| `ParameterMapperTest.php` | `test_gemini_removes_additional_properties_from_schema` | **Новый.** Рекурсивное удаление additionalProperties |
| `ParameterMapperTest.php` | `test_deepseek_downgrades_json_schema_to_json_object` | **Новый.** json_schema → json_object для DeepSeek |
| `ParameterMapperTest.php` | `test_maps_claude_json_schema_to_output_config` | **Новый.** json_schema → `output_config.format` для Claude (не `response_format`) |
| `ParameterMapperTest.php` | `test_claude_ignores_json_object_without_schema` | **Новый.** json_object без schema → output_config не добавляется |
| `StructuredOutputResolverTest.php` | `test_resolves_support_level_for_each_provider` | **Новый.** Корректный маппинг уровня поддержки |
| `StructuredOutputResolverTest.php` | `test_no_fallback_for_claude_json_schema` | **Новый.** Claude + json_schema → fallback NOT needed (нативная поддержка) |
| `StructuredOutputResolverTest.php` | `test_no_fallback_for_openai_json_schema` | **Новый.** OpenAI + json_schema → fallback not needed |
| `StructuredOutputResolverTest.php` | `test_needs_fallback_for_deepseek_json_schema` | **Новый.** DeepSeek + json_schema → fallback needed |
| `StructuredOutputFallbackTest.php` | `test_injects_schema_into_system_prompt` | **Новый.** Проверяет формат инъекции |
| `StructuredOutputFallbackTest.php` | `test_injects_strict_mode_instruction` | **Новый.** Strict mode добавляет дополнительный текст |
| `RequestValidatorTest.php` | `test_validates_response_format_type` | **Новый.** Допустимые значения type |
| `RequestValidatorTest.php` | `test_json_schema_requires_schema_field` | **Новый.** schema обязательна |
| `RequestValidatorTest.php` | `test_json_schema_requires_valid_json` | **Новый.** Валидный JSON в schema |
| `RequestValidatorTest.php` | `test_json_schema_requires_name` | **Новый.** name обязательно |
| `ResponseParserTest.php` | `test_structured_output_fallback_flag_propagated` | **Новый.** Флаг передаётся, а не хардкодится |

### 4.2. Feature-тесты

| Файл | Тест | Что проверяет |
|------|------|---------------|
| `LlmRequestValidationTest.php` | `test_rejects_invalid_response_format_type` | HTTP 400 при невалидном type |
| `LlmRequestValidationTest.php` | `test_rejects_json_schema_without_schema` | HTTP 400 при отсутствии schema |
| `LlmRequestValidationTest.php` | `test_accepts_valid_json_schema_response_format` | HTTP 202 при корректном json_schema |
| `ProcessLlmRequestTest.php` | `test_openai_json_schema_passed_natively` | response_format в теле запроса к OpenAI |
| `ProcessLlmRequestTest.php` | `test_claude_json_schema_passed_natively` | Schema в `output_config.format`, `structured_output_fallback: false` в callback |
| `ProcessLlmRequestTest.php` | `test_deepseek_json_schema_uses_fallback` | Schema инъецирована в system prompt, `structured_output_fallback: true` в callback |
| `ProcessLlmRequestTest.php` | `test_gemini_json_schema_passed_natively` | responseSchema в generationConfig |

---

## 5. Новые файлы (сводка)

| Файл | Тип | Назначение |
|------|-----|------------|
| `app/Components/PromptAssembler/Enums/StructuredOutputSupport.php` | Enum | Уровни поддержки structured output |
| `app/Components/PromptAssembler/StructuredOutputResolver.php` | Класс | Определение уровня поддержки для провайдера |
| `app/Components/PromptAssembler/StructuredOutputFallback.php` | Класс | Инъекция schema в system prompt |
| `tests/Unit/Components/PromptAssembler/StructuredOutputResolverTest.php` | Тест | Unit-тесты StructuredOutputResolver |
| `tests/Unit/Components/PromptAssembler/StructuredOutputFallbackTest.php` | Тест | Unit-тесты StructuredOutputFallback |

---

## 6. Изменяемые файлы (сводка)

| Файл | Что менять |
|------|------------|
| `app/Components/PromptAssembler/ParameterMapper.php` | Маппинг Gemini, DeepSeek downgrade |
| `app/Components/PromptAssembler/PromptAssembler.php` | Инъекция fallback, новые зависимости в конструктор |
| `app/Components/PromptAssembler/DTO/AssembledPayload.php` | Поле `structuredOutputFallback` |
| `app/Components/PromptAssembler/DTO/AssembledMessages.php` | Метод `withSystemPrompt()` |
| `app/Components/PromptAssembler/Formatters/ClaudeFormatter.php` | Передача `structuredOutputFallback: false` |
| `app/Components/PromptAssembler/Formatters/OpenAiFormatter.php` | Передача `structuredOutputFallback: false` |
| `app/Components/PromptAssembler/Formatters/GeminiFormatter.php` | Передача `structuredOutputFallback: false` |
| `app/Components/ProviderGateway/ResponseParser.php` | Параметр `$structuredOutputFallback`, убрать хардкод |
| `app/Components/RequestPipeline/RequestValidator.php` | Валидация response_format |
| `app/Jobs/ProcessLlmRequest.php` | Прокидывание флага fallback |
| `tests/Unit/Components/PromptAssembler/ParameterMapperTest.php` | Новые тесты |
| `tests/Unit/Components/RequestPipeline/RequestValidatorTest.php` | Новые тесты |
| `tests/Unit/Components/ProviderGateway/ResponseParserTest.php` | Новый тест |

---

## 7. Порядок реализации

Рекомендуемый порядок (зависимости):

1. **Валидация** (§3.1) — `RequestValidator.php` + тесты. Можно делать независимо.
2. **Enum + Resolver** (§3.2) — `StructuredOutputSupport`, `StructuredOutputResolver` + тесты.
3. **Маппинг Gemini** (§3.4) — `ParameterMapper::mapGemini()` + тесты.
4. **Маппинг DeepSeek** (§3.5) — `ParameterMapper::mapOpenAi()` + тесты.
5. **DTO изменения** (§3.7) — `AssembledPayload`, `AssembledMessages`.
6. **Fallback-эмуляция** (§3.6) — `StructuredOutputFallback` + тесты.
7. **Интеграция в PromptAssembler** (§3.6) — связка resolver + fallback + assembler.
8. **ResponseParser + флаг** (§3.8) — убрать хардкод, прокинуть флаг.
9. **ProcessLlmRequest** (§3.8) — финальная интеграция.
10. **Feature-тесты** (§4.2).

---

## 8. Правки в документации

### 8.1. `documentation/specification_llm_service.md`

#### §10.2 — уточнить поведение fallback

Текущий текст:
> Если провайдер не поддерживает structured output, сервис ДОЛЖЕН:
> 1. Добавить JSON-инструкцию в системный промт.
> 2. Попытаться распарсить ответ как JSON.
> 3. Если парсинг не удался — вернуть сырой ответ с флагом `"structured_output_fallback": true` в callback-ответе.

**Дополнить** (добавить после пункта 3):

```
4. Валидация ответа LLM на соответствие JSON Schema — ответственность клиента.
   Сервис не выполняет post-validation. Флаг `structured_output_fallback: true`
   в callback-ответе информирует клиента, что schema была передана через эмуляцию
   (system prompt), а не через нативный механизм провайдера, и ответ может
   не соответствовать схеме.

5. Таблица поддержки structured output по провайдерам:

   | Провайдер | json_object | json_schema (strict) | Стратегия при json_schema |
   |-----------|:-----------:|:--------------------:|--------------------------|
   | OpenAI    | Нативно     | Нативно              | Нативная передача        |
   | Claude    | Нативно     | Нативно              | Нативная передача (`output_config.format`) |
   | Gemini    | Нативно     | Нативно              | Нативная передача        |
   | DeepSeek  | Нативно     | Эмуляция             | json_object + schema в system prompt |
   | Mistral   | Нативно     | Нативно              | Нативная передача        |
```

### 8.2. `documentation/microservices_instruction.md`

#### Добавить раздел «Structured Output (JSON Schema)» после раздела «Минимальный пример запроса»

```markdown
## Structured Output (JSON Schema)

Для получения ответа LLM в строго определённом JSON-формате используйте
`response_format` с типом `json_schema`:

### Пример запроса с JSON Schema

```xml
<parameters>
  <temperature>0.0</temperature>
  <max_tokens>2048</max_tokens>
  <response_format>
    <type>json_schema</type>
    <name>trade_recommendation</name>
    <strict>true</strict>
    <schema><![CDATA[
      {
        "type": "object",
        "properties": {
          "action": {"type": "string", "enum": ["LONG", "SHORT", "NO_TRADE"]},
          "confidence": {"type": "number"},
          "reasoning": {"type": "string"}
        },
        "required": ["action", "confidence", "reasoning"],
        "additionalProperties": false
      }
    ]]></schema>
  </response_format>
</parameters>
```

### Поведение по провайдерам

- **OpenAI, Claude, Gemini, Mistral** — schema передаётся нативно через API
  провайдера. Провайдер гарантирует соответствие ответа схеме.
- **DeepSeek** — schema инъецируется в system prompt как текстовая
  инструкция. В callback-ответе поле `structured_output_fallback` будет `true`.
  Валидация ответа LLM на соответствие схеме — ответственность клиента.

### Поля callback при structured output

| Поле | Тип | Описание |
|------|-----|----------|
| `structured_output_fallback` | boolean | `true` если structured output был эмулирован (не нативный). Клиент должен самостоятельно валидировать ответ |
```

### 8.3. `documentation/specification_llm_service.md`

#### §12.3 — добавить код ошибки

В таблицу кодов ошибок синхронного ответа добавить:

```
| `INVALID_RESPONSE_FORMAT` | 400 | Невалидный response_format: отсутствует schema, невалидный JSON в schema, отсутствует name |
```

**Примечание:** Можно использовать существующий код `INVALID_PARAMETER` вместо введения нового. Решение на усмотрение разработчика. В §3.1 указан `INVALID_PARAMETER` — это предпочтительный вариант для консистентности.

---

## 9. Ограничения и допущения

1. **Параметр `response_format` опционален.** Если клиент не передаёт `<response_format>` в XML-запросе, сервис не добавляет никаких параметров формата ответа в запрос к провайдеру. Поведение провайдера — по умолчанию (свободный текст).

2. **Сервис не валидирует ответ LLM на соответствие schema.** Валидация ответа — ответственность клиента. Сервис только доставляет ответ и информирует клиента через флаг `structured_output_fallback`, была ли schema передана нативно или через эмуляцию.

3. **Claude Tool Use** как альтернатива structured output — **не рассматривается** в данном ТЗ. Anthropic рекомендует tool_use для строгого JSON, но это потребует другой архитектуры (клиент уже может использовать tools через секцию `<tools>`). В будущем можно добавить автоматическое преобразование json_schema → tool_use для Claude.

4. **Streaming + structured output** — при стриминге (`stream=true`) response_format передаётся провайдеру. Streaming job изменяется аналогично по тому же паттерну. Не входит в данное ТЗ.

5. **Кеширование schema** — JSON Schema декодируется из строки при каждом запросе. При высокой нагрузке можно добавить кеширование декодированных схем. Не входит в первый этап.

6. **Gemini `additionalProperties`** — Gemini API не поддерживает это ключевое слово в responseSchema. Реализация рекурсивно удаляет его перед отправкой. Это задокументированное ограничение Gemini API.
