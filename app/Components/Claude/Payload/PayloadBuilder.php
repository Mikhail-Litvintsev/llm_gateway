<?php

// TODO: verify output_config field name against docs.claude.com/en/api/messages before Phase 2 merge

declare(strict_types=1);

namespace App\Components\Claude\Payload;

use App\Components\Claude\DTO\ContextManagementConfig;
use App\Components\Claude\DTO\ThinkingSpec;
use App\Components\Claude\Enums\ThinkingMode;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\ToolTypeCatalog;
use App\Components\Routing\DTO\ResolvedModel;
use App\Components\Routing\ModelResolver;
use App\Models\Client;

final class PayloadBuilder
{
    private const int MAX_PAYLOAD_BYTES = 32 * 1024 * 1024;

    private const int JSON_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly ModelResolver $models,
        private readonly FileSourceResolver $fileSourceResolver,
        private readonly array $betaHeaderMap,
    ) {}

    /**
     * @param  array<string, mixed>  $validatedPayload  Already validated by MessageRequestValidator
     *
     * @throws PayloadBuildException On build-time rule violations:
     *                               1. max_tokens exceeds model max_output
     *                               2. Prefill incompatibility (assistant last message on unsupported model)
     *                               3. Thinking type validation (enabled/adaptive support)
     *                               4. top_p must be >= 0.95 when thinking is enabled
     *                               5. Temperature and thinking are mutually exclusive
     *                               6. Citations and structured output are mutually exclusive
     *                               7. Payload size exceeds 32MB
     *                               8. Service tier permission check
     *                               9. Inference geo permission check
     */
    public function build(
        array $validatedPayload,
        Client $client,
        ?ContextManagementConfig $contextManagement = null,
    ): BuiltPayload {
        $alias = $validatedPayload['model'] ?? config('llm.claude.default_model_alias');
        $resolved = $this->models->resolve($alias);
        $capabilities = $resolved->capabilities;

        $warnings = [];

        $this->enforceMaxTokensCap($validatedPayload, $capabilities, $alias);
        $this->enforcePrefillCompatibility($validatedPayload, $capabilities, $alias);

        $thinkingSpec = ThinkingSpec::fromArray($validatedPayload['thinking'] ?? null);
        $this->validateThinking($thinkingSpec, $validatedPayload, $capabilities, $alias, $warnings);

        $this->enforceCitationsVsStructuredOutput($validatedPayload);
        $this->enforceServiceTierPermission($validatedPayload, $client);
        $this->enforceInferenceGeo($validatedPayload, $client);

        if ($thinkingSpec->isEnabled()) {
            $validatedPayload['thinking'] = $this->buildThinkingPayload($thinkingSpec);
        }

        $payload = $this->assemblePayload($validatedPayload, $resolved);
        $payload['messages'] = $this->normaliseMessageContent($payload['messages']);
        $payload['messages'] = $this->resolveFileSourcesInMessages($payload['messages'], $client);

        $serverToolTypes = [];
        $hasPtcTool = false;
        if (! empty($payload['tools'])) {
            [$payload['tools'], $serverToolTypes, $hasPtcTool] = $this->normaliseTools($payload['tools']);
        }

        if ($hasPtcTool && ! empty($validatedPayload['disable_parallel_tool_use'])) {
            throw PayloadBuildException::invalidRequest('PTC is incompatible with disable_parallel_tool_use');
        }

        $contextManagement ??= ContextManagementConfig::fromArray($validatedPayload['context_management'] ?? null);
        $contextEdits = [];

        if (! $contextManagement->isEmpty()) {
            $contextEdits = $this->assembleContextManagementEdits($contextManagement, $capabilities, $alias);
            $payload['context_management'] = ['edits' => $contextEdits];
        }

        $betaFeatures = $this->detectBetaFeatures($validatedPayload, $capabilities);

        if ($contextEdits !== []) {
            $betaFeatures[] = 'context_management';
            if ($contextManagement->compaction !== null) {
                $betaFeatures[] = 'compaction';
            }
        }

        if (in_array(ToolTypeCatalog::MEMORY, $serverToolTypes, true)) {
            $betaFeatures[] = 'context_management';
        }
        if (in_array(ToolTypeCatalog::COMPUTER, $serverToolTypes, true)) {
            $betaFeatures[] = 'computer_use';
        }

        $betaHeaders = $this->collectBetaHeaders(array_unique($betaFeatures));

        $jsonBody = $this->serialize($payload);
        $this->enforcePayloadSizeLimit($jsonBody);

        return new BuiltPayload(
            jsonBody: $jsonBody,
            betaHeaders: $betaHeaders,
            modelSnapshot: $resolved->snapshot,
            modelAlias: $resolved->alias,
            payloadSizeBytes: strlen($jsonBody),
            decodedPayload: $payload,
            serverToolTypes: $serverToolTypes,
            warnings: $warnings,
        );
    }

    private function enforceMaxTokensCap(array $payload, array $capabilities, string $alias): void
    {
        $maxTokens = $payload['max_tokens'] ?? null;
        $maxOutput = $capabilities['max_output'] ?? null;

        if ($maxTokens !== null && $maxOutput !== null && $maxTokens > $maxOutput) {
            throw PayloadBuildException::invalidRequest(
                "max_tokens ($maxTokens) exceeds model $alias maximum output ($maxOutput)"
            );
        }
    }

    private function enforcePrefillCompatibility(array $payload, array $capabilities, string $alias): void
    {
        $messages = $payload['messages'] ?? [];

        if (empty($messages)) {
            return;
        }

        $lastMessage = end($messages);

        if (($lastMessage['role'] ?? '') === 'assistant' && ! ($capabilities['supports_prefill'] ?? true)) {
            throw PayloadBuildException::invalidRequest(
                "Model $alias does not support assistant prefill"
            );
        }
    }

    private function validateThinking(
        ThinkingSpec $spec,
        array $payload,
        array $capabilities,
        string $alias,
        array &$warnings,
    ): void {
        if (! $spec->isEnabled()) {
            return;
        }

        match ($spec->mode) {
            ThinkingMode::Adaptive => $this->validateAdaptiveThinking($spec, $capabilities, $alias),
            ThinkingMode::Manual => $this->validateManualThinking($spec, $payload, $capabilities, $alias, $warnings),
            default => null,
        };

        $this->validateSamplingWithThinking($payload);
    }

    private function validateAdaptiveThinking(ThinkingSpec $spec, array $capabilities, string $alias): void
    {
        if (! ($capabilities['supports_adaptive_thinking'] ?? false)) {
            throw PayloadBuildException::invalidRequest("Adaptive thinking not supported on $alias");
        }

        $effort = $spec->effort ?? config('llm.claude.adaptive_thinking.default_effort', 'medium');
        if (! in_array($effort, ['low', 'medium', 'high'], true)) {
            throw PayloadBuildException::invalidRequest("Invalid thinking effort: '$effort' — must be low, medium, or high");
        }
    }

    private function validateManualThinking(
        ThinkingSpec $spec,
        array $payload,
        array $capabilities,
        string $alias,
        array &$warnings,
    ): void {
        if (! ($capabilities['supports_thinking'] ?? false)) {
            throw PayloadBuildException::invalidRequest("$alias does not support extended thinking");
        }

        $budget = $spec->budgetTokens;
        if ($budget !== null && $budget <= 0) {
            throw PayloadBuildException::invalidRequest('budget_tokens must be greater than 0');
        }

        $requiresBelowMax = ! ($capabilities['supports_adaptive_thinking'] ?? false);
        $maxTokens = $payload['max_tokens'] ?? null;

        if ($requiresBelowMax && $budget !== null && $maxTokens !== null && $budget >= $maxTokens) {
            throw PayloadBuildException::invalidRequest(
                "budget_tokens ($budget) must be less than max_tokens ($maxTokens) on $alias"
            );
        }

        if ($capabilities['supports_adaptive_thinking'] ?? false) {
            $warnings[] = [
                'code' => 'thinking.manual_deprecated',
                'message' => "Manual thinking budget_tokens is deprecated on $alias — prefer thinking.type: 'adaptive'",
            ];
        }
    }

    private function validateSamplingWithThinking(array $payload): void
    {
        if (isset($payload['tool_choice'])) {
            $tcType = $payload['tool_choice']['type'] ?? $payload['tool_choice'] ?? null;
            if (! in_array($tcType, ['auto', 'none'], true)) {
                throw PayloadBuildException::invalidRequest(
                    "tool_choice must be 'auto' or 'none' when thinking is enabled"
                );
            }
        }

        if (isset($payload['top_p'])) {
            $topP = (float) $payload['top_p'];
            if ($topP < 0.95 || $topP > 1.0) {
                throw PayloadBuildException::invalidRequest(
                    "top_p must be within [0.95, 1.0] when thinking is enabled, got $topP"
                );
            }
        }
    }

    private function buildThinkingPayload(ThinkingSpec $spec): array
    {
        return match ($spec->mode) {
            ThinkingMode::Adaptive => [
                'type' => 'adaptive',
                'effort' => $spec->effort ?? config('llm.claude.adaptive_thinking.default_effort', 'medium'),
            ],
            ThinkingMode::Manual => array_filter([
                'type' => 'enabled',
                'budget_tokens' => $spec->budgetTokens,
            ], fn ($v) => $v !== null),
            default => [],
        };
    }

    private function enforceCitationsVsStructuredOutput(array $payload): void
    {
        $citationsEnabled = ($payload['citations']['enabled'] ?? false) === true;
        $hasOutputFormat = isset($payload['output_config']['format']);

        if ($citationsEnabled && $hasOutputFormat) {
            throw PayloadBuildException::invalidRequest(
                'Citations and structured output formats are mutually exclusive'
            );
        }
    }

    private function enforceServiceTierPermission(array $payload, Client $client): void
    {
        $serviceTier = $payload['service_tier'] ?? null;

        if ($serviceTier !== 'priority') {
            return;
        }

        $allowedFeatures = $client->allowed_features ?? [];

        if (! ($allowedFeatures['priority_tier'] ?? false)) {
            throw PayloadBuildException::permissionError(
                'Client is not authorized to use priority service tier'
            );
        }
    }

    private function enforceInferenceGeo(array $payload, Client $client): void
    {
        $inferenceGeo = $payload['inference_geo'] ?? null;

        if ($inferenceGeo === null) {
            return;
        }

        $clientGeo = $client->inference_geo ?? null;
        $allowedFeatures = $client->allowed_features ?? [];

        if ($clientGeo !== $inferenceGeo && ! ($allowedFeatures['inference_geo_override'] ?? false)) {
            throw PayloadBuildException::invalidRequest(
                "Inference geo '$inferenceGeo' is not allowed for this client"
            );
        }
    }

    private function enforcePayloadSizeLimit(string $jsonBody): void
    {
        if (strlen($jsonBody) > self::MAX_PAYLOAD_BYTES) {
            throw PayloadBuildException::requestTooLarge(
                'Payload exceeds maximum size of 32MB'
            );
        }
    }

    private function normaliseMessageContent(array $messages): array
    {
        foreach ($messages as &$message) {
            $content = $message['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as &$block) {
                if (! is_array($block) || ($block['type'] ?? '') !== 'search_result') {
                    continue;
                }
                $block = $this->normaliseSearchResultBlock($block);
            }
        }

        return $messages;
    }

    private function normaliseSearchResultBlock(array $block): array
    {
        $allowedKeys = ['type', 'title', 'source', 'content', 'citations'];
        $unknown = array_diff(array_keys($block), $allowedKeys);

        if ($unknown !== []) {
            throw PayloadBuildException::invalidRequest(
                'Unknown key on search_result block: '.reset($unknown)
            );
        }

        foreach (['title', 'source', 'content'] as $required) {
            if (! isset($block[$required])) {
                throw PayloadBuildException::invalidRequest(
                    "search_result block missing required key: $required"
                );
            }
        }

        foreach ($block['content'] as $inner) {
            if (! is_array($inner) || ($inner['type'] ?? '') !== 'text') {
                throw PayloadBuildException::invalidRequest(
                    'search_result.content only accepts text blocks'
                );
            }
        }

        if (isset($block['citations']) && (! is_array($block['citations']) || ! array_key_exists('enabled', $block['citations']))) {
            throw PayloadBuildException::invalidRequest(
                'search_result: citations must be {enabled: bool}'
            );
        }

        return $block;
    }

    private function resolveFileSourcesInMessages(array $messages, Client $client): array
    {
        $clientId = (int) $client->id;
        $allowRaw = (bool) ($client->allowed_features['allow_raw_anthropic_file_ids'] ?? false);

        foreach ($messages as &$message) {
            $content = $message['content'] ?? [];

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as &$block) {
                if (! is_array($block)) {
                    continue;
                }

                $sourceType = $block['source']['type'] ?? null;

                if ($sourceType !== 'file') {
                    continue;
                }

                $block['source'] = $this->fileSourceResolver->resolve($block['source'], $clientId, $allowRaw);
            }
        }

        return $messages;
    }

    private function assemblePayload(array $input, ResolvedModel $resolved): array
    {
        $payload = [
            'model' => $resolved->snapshot,
            'messages' => $input['messages'],
            'max_tokens' => $input['max_tokens'],
        ];

        if (isset($input['system'])) {
            $payload['system'] = $input['system'];
        }

        if (isset($input['temperature']) && ! isset($input['thinking'])) {
            $payload['temperature'] = $input['temperature'];
        }

        if (isset($input['top_p'])) {
            $payload['top_p'] = $input['top_p'];
        }

        if (isset($input['top_k'])) {
            $payload['top_k'] = $input['top_k'];
        }

        if (! empty($input['stop_sequences'])) {
            $payload['stop_sequences'] = $input['stop_sequences'];
        }

        if (! empty($input['tools'])) {
            $payload['tools'] = $input['tools'];
        }

        if (isset($input['tool_choice'])) {
            $payload['tool_choice'] = $input['tool_choice'];
        }

        if (isset($input['thinking'])) {
            $payload['thinking'] = $input['thinking'];
        }

        if (isset($input['output_config'])) {
            $payload['output_config'] = $input['output_config'];
        }

        if (isset($input['cache_control'])) {
            $payload['cache_control'] = $input['cache_control'];
        }

        if (isset($input['service_tier'])) {
            $payload['service_tier'] = $input['service_tier'];
        }

        if (isset($input['metadata'])) {
            $payload['metadata'] = $input['metadata'];
        }

        if (isset($input['inference_geo'])) {
            $payload['inference_geo'] = $input['inference_geo'];
        }

        if (isset($input['speed'])) {
            $payload['speed'] = $input['speed'];
        }

        if (! empty($input['skills'])) {
            $payload['skills'] = $input['skills'];
        }

        if (! empty($input['mcp_servers'])) {
            $payload['mcp_servers'] = $input['mcp_servers'];
        }

        if (! empty($input['stream'])) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /** @return string[] */
    private function detectBetaFeatures(array $payload, array $capabilities): array
    {
        $features = [];

        if (isset($payload['cache_control']) || $this->hasCacheControlInMessages($payload)) {
            $features[] = 'prompt_caching';
        }

        if (isset($payload['thinking'])) {
            $features[] = 'extended_thinking';
        }

        if (($capabilities['supports_compaction'] ?? false) && isset($payload['system'])) {
            $features[] = 'compaction';
        }

        if ($this->hasFileContent($payload)) {
            $features[] = 'files_api';
        }

        if (($payload['max_tokens'] ?? 0) > 64_000) {
            $features[] = 'output_300k';
        }

        if (($payload['speed'] ?? null) === 'fast') {
            $features[] = 'fast_mode';
        }

        if (! empty($payload['skills'])) {
            $features[] = 'skills';
        }

        if ($this->hasMcpServers($payload)) {
            $features[] = 'mcp_client';
        }

        if ($this->hasComputerUseTool($payload)) {
            $features[] = 'computer_use';
        }

        return array_unique($features);
    }

    /** @return string[] */
    private function collectBetaHeaders(array $features): array
    {
        $headers = [];

        foreach ($features as $feature) {
            $header = $this->betaHeaderMap[$feature] ?? null;

            if ($header !== null) {
                $headers[] = $header;
            }
        }

        return array_unique($headers);
    }

    private function serialize(array $payload): string
    {
        $result = json_encode($payload, self::JSON_OPTIONS);

        if ($result === false) {
            throw new PayloadBuildException(
                'Failed to serialize payload: '.json_last_error_msg(),
            );
        }

        return $result;
    }

    private const array SERVER_TOOL_TYPES = [
        ToolTypeCatalog::WEB_SEARCH,
        ToolTypeCatalog::WEB_FETCH,
        ToolTypeCatalog::CODE_EXECUTION,
        ToolTypeCatalog::TOOL_SEARCH_REGEX,
        ToolTypeCatalog::TOOL_SEARCH_BM25,
        ToolTypeCatalog::MEMORY,
        ToolTypeCatalog::BASH,
        ToolTypeCatalog::TEXT_EDITOR,
        ToolTypeCatalog::COMPUTER,
    ];

    /** @return array{array, string[], bool} [normalisedTools, serverToolTypes, hasPtcTool] */
    private function normaliseTools(array $rawTools): array
    {
        $serverToolTypes = [];
        $hasToolSearch = false;

        foreach ($rawTools as $tool) {
            $type = $tool['type'] ?? null;
            if (is_string($type) && in_array($type, self::SERVER_TOOL_TYPES, true)) {
                if (! in_array($type, $serverToolTypes, true)) {
                    $serverToolTypes[] = $type;
                }
                if (ToolTypeCatalog::isToolSearch($type)) {
                    $hasToolSearch = true;
                }
            }
        }

        $hasCodeExecution = in_array(ToolTypeCatalog::CODE_EXECUTION, $serverToolTypes, true);
        $hasPtcTool = false;
        $normalised = [];

        foreach ($rawTools as $tool) {
            $type = $tool['type'] ?? null;

            if (is_string($type) && in_array($type, self::SERVER_TOOL_TYPES, true)) {
                $normalised[] = $this->normaliseServerTool($type, $tool);
            } else {
                $norm = $this->normaliseCustomTool($tool, $hasCodeExecution);
                if (isset($norm['allowed_callers'])) {
                    $hasPtcTool = true;
                }
                $normalised[] = $norm;
            }
        }

        // PTC + disable_parallel_tool_use check deferred to build() where payload is available

        $this->enforceMemoryUniqueness($serverToolTypes);

        $customCount = count($normalised) - count($serverToolTypes);
        $customCap = $hasToolSearch ? 10_000 : (int) config('llm.claude.max_custom_tools', 128);

        if ($customCount > $customCap) {
            throw PayloadBuildException::invalidRequest(
                "Too many custom tools ($customCount), maximum is $customCap"
            );
        }

        return [$normalised, $serverToolTypes, $hasPtcTool];
    }

    private function normaliseServerTool(string $type, array $tool): array
    {
        return match ($type) {
            ToolTypeCatalog::WEB_SEARCH => $this->normaliseWebSearch($tool),
            ToolTypeCatalog::WEB_FETCH => $this->normaliseWebFetch($tool),
            ToolTypeCatalog::CODE_EXECUTION => $this->normaliseCodeExecution($tool),
            ToolTypeCatalog::TOOL_SEARCH_REGEX,
            ToolTypeCatalog::TOOL_SEARCH_BM25 => $this->normaliseToolSearch($tool),
            ToolTypeCatalog::MEMORY => $this->normaliseMemory($tool),
            ToolTypeCatalog::BASH => $this->normaliseBash($tool),
            ToolTypeCatalog::TEXT_EDITOR => $this->normaliseTextEditor($tool),
            ToolTypeCatalog::COMPUTER => $this->normaliseComputer($tool),
        };
    }

    private function normaliseWebSearch(array $tool): array
    {
        $allowed = ['type', 'name', 'max_uses', 'allowed_domains', 'blocked_domains', 'user_location'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        if (isset($tool['allowed_domains'], $tool['blocked_domains'])) {
            throw PayloadBuildException::invalidRequest(
                'web_search: allowed_domains and blocked_domains cannot be combined'
            );
        }

        return $this->pickKeys($tool, $allowed);
    }

    private function normaliseWebFetch(array $tool): array
    {
        $allowed = ['type', 'name', 'max_content_tokens', 'citations'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        if (isset($tool['citations']) && (! is_array($tool['citations']) || ! array_key_exists('enabled', $tool['citations']))) {
            throw PayloadBuildException::invalidRequest(
                'web_fetch: citations must be {enabled: bool}'
            );
        }

        return $this->pickKeys($tool, $allowed);
    }

    private function normaliseCodeExecution(array $tool): array
    {
        $allowed = ['type', 'name', 'container'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    private function normaliseToolSearch(array $tool): array
    {
        $allowed = ['type', 'name', 'max_results'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    private function normaliseMemory(array $tool): array
    {
        $allowed = ['type', 'name'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    private function normaliseBash(array $tool): array
    {
        $allowed = ['type', 'name'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    private function normaliseTextEditor(array $tool): array
    {
        $allowed = ['type', 'name'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    private function normaliseComputer(array $tool): array
    {
        $allowed = ['type', 'name', 'display_width_px', 'display_height_px', 'display_number'];
        $this->rejectUnknownKeys($tool, $allowed, $tool['type']);

        if (! isset($tool['display_width_px'], $tool['display_height_px'])) {
            throw PayloadBuildException::invalidRequest(
                'computer: display_width_px and display_height_px are required'
            );
        }

        $result = $this->pickKeys($tool, $allowed);
        $result['display_number'] ??= 1;

        return $result;
    }

    private function normaliseCustomTool(array $tool, bool $hasCodeExecution): array
    {
        if (! isset($tool['allowed_callers'])) {
            return $tool;
        }

        return $this->normaliseCustomToolWithPtc($tool, $hasCodeExecution);
    }

    private function normaliseCustomToolWithPtc(array $tool, bool $hasCodeExecution): array
    {
        $callers = $tool['allowed_callers'];

        if (! is_array($callers) || $callers === []) {
            throw PayloadBuildException::invalidRequest('allowed_callers must contain at least one entry');
        }

        $validValues = ['direct', ToolTypeCatalog::CODE_EXECUTION];
        $normalized = [];

        foreach ($callers as $v) {
            if (! in_array($v, $validValues, true)) {
                throw PayloadBuildException::invalidRequest("Invalid allowed_callers value: $v");
            }
            $normalized[$v] = true;
        }

        $tool['allowed_callers'] = array_keys($normalized);

        if (isset($normalized[ToolTypeCatalog::CODE_EXECUTION]) && ! $hasCodeExecution) {
            throw PayloadBuildException::invalidRequest(
                'allowed_callers references code_execution but code_execution tool is absent'
            );
        }

        if (($tool['strict'] ?? false) === true) {
            throw PayloadBuildException::invalidRequest('PTC is incompatible with strict: true');
        }

        return $tool;
    }

    private function rejectUnknownKeys(array $tool, array $allowed, string $type): void
    {
        $unknown = array_diff(array_keys($tool), $allowed);

        if ($unknown !== []) {
            $key = reset($unknown);
            throw PayloadBuildException::invalidRequest(
                "Unknown option '$key' on server tool '$type'"
            );
        }
    }

    private function pickKeys(array $tool, array $keys): array
    {
        return array_intersect_key($tool, array_flip($keys));
    }

    private function enforceMemoryUniqueness(array $serverToolTypes): void
    {
        $memoryCount = 0;
        foreach ($serverToolTypes as $type) {
            if (ToolTypeCatalog::isMemoryTool($type)) {
                $memoryCount++;
            }
        }

        if ($memoryCount > 1) {
            throw PayloadBuildException::invalidRequest('memory tool must appear at most once');
        }
    }

    private function assembleContextManagementEdits(
        ContextManagementConfig $config,
        array $capabilities,
        string $alias,
    ): array {
        $edits = [];

        if ($config->compaction !== null) {
            if (! ($capabilities['supports_compaction'] ?? false)) {
                throw PayloadBuildException::invalidRequest("Compaction not supported on model $alias");
            }
            $edits[] = ['type' => ToolTypeCatalog::EDIT_COMPACT, ...$config->compaction];
        }

        if ($config->clearToolUses !== null) {
            $this->requireContextManagementSupport($capabilities, $alias);
            $edits[] = ['type' => ToolTypeCatalog::EDIT_CLEAR_TOOL_USES, ...$config->clearToolUses];
        }

        if ($config->clearThinking !== null) {
            $this->requireContextManagementSupport($capabilities, $alias);
            $edits[] = ['type' => ToolTypeCatalog::EDIT_CLEAR_THINKING, ...$config->clearThinking];
        }

        return $edits;
    }

    private function requireContextManagementSupport(array $capabilities, string $alias): void
    {
        if (! ($capabilities['supports_context_management_edits'] ?? true)) {
            throw PayloadBuildException::invalidRequest("Context management edits not supported on model $alias");
        }
    }

    private function hasCacheControlInMessages(array $payload): bool
    {
        foreach ($payload['messages'] ?? [] as $message) {
            if (isset($message['cache_control'])) {
                return true;
            }

            $content = $message['content'] ?? [];
            if (is_array($content) && array_any(
                $content,
                static fn (mixed $block): bool => is_array($block) && isset($block['cache_control']),
            )) {
                return true;
            }
        }

        return false;
    }

    private function hasFileContent(array $payload): bool
    {
        return array_any(
            $payload['messages'] ?? [],
            static fn (mixed $message): bool => is_array($message) && is_array($message['content'] ?? []) && array_any(
                $message['content'] ?? [],
                static fn (mixed $block): bool => is_array($block) && ($block['type'] ?? '') === 'file',
            ),
        );
    }

    private function hasMcpServers(array $payload): bool
    {
        return ! empty($payload['mcp_servers']);
    }

    private function hasComputerUseTool(array $payload): bool
    {
        return array_any(
            $payload['tools'] ?? [],
            static fn (mixed $tool): bool => ($tool['type'] ?? '') === ToolTypeCatalog::COMPUTER,
        );
    }
}
