<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload;

use App\Components\Claude\DTO\ContextManagementConfig;
use App\Components\Claude\DTO\ThinkingSpec;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Normalisers\MessageContentNormaliser;
use App\Components\Claude\Payload\Normalisers\ToolNormaliser;
use App\Components\Claude\Payload\Validators\CitationsStructuredOutputEnforcer;
use App\Components\Claude\Payload\Validators\InferenceGeoGuard;
use App\Components\Claude\Payload\Validators\MaxTokensEnforcer;
use App\Components\Claude\Payload\Validators\PrefillCompatibilityEnforcer;
use App\Components\Claude\Payload\Validators\ServiceTierGuard;
use App\Components\Claude\Payload\Validators\ThinkingValidator;
use App\Components\Claude\ToolTypeCatalog;
use App\Components\Routing\DTO\ResolvedModel;
use App\Components\Routing\ModelResolver;
use App\Models\Client;

final class PayloadBuilder
{
    private const int MAX_PAYLOAD_BYTES = 32 * 1024 * 1024;

    private const int JSON_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, string>  $betaHeaderMap
     */
    public function __construct(
        private readonly ModelResolver $models,
        private readonly FileSourceResolver $fileSourceResolver,
        private readonly MaxTokensEnforcer $maxTokensEnforcer,
        private readonly PrefillCompatibilityEnforcer $prefillEnforcer,
        private readonly CitationsStructuredOutputEnforcer $citationsEnforcer,
        private readonly ServiceTierGuard $serviceTierGuard,
        private readonly InferenceGeoGuard $inferenceGeoGuard,
        private readonly ThinkingValidator $thinkingValidator,
        private readonly MessageContentNormaliser $messageContentNormaliser,
        private readonly ToolNormaliser $toolNormaliser,
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

        $this->maxTokensEnforcer->enforce($validatedPayload, $capabilities, $alias);
        $this->prefillEnforcer->enforce($validatedPayload, $capabilities, $alias);

        $thinkingSpec = ThinkingSpec::fromArray($validatedPayload['thinking'] ?? null);
        $this->thinkingValidator->validate($thinkingSpec, $validatedPayload, $capabilities, $alias, $warnings);

        $this->citationsEnforcer->enforce($validatedPayload);
        $this->serviceTierGuard->enforce($validatedPayload, $client);
        $this->inferenceGeoGuard->enforce($validatedPayload, $client);

        if ($thinkingSpec->isEnabled()) {
            $validatedPayload['thinking'] = $this->thinkingValidator->buildPayload($thinkingSpec);
        }

        $payload = $this->assemblePayload($validatedPayload, $resolved);
        $payload['messages'] = $this->messageContentNormaliser->normalise($payload['messages']);
        $payload['messages'] = $this->resolveFileSourcesInMessages($payload['messages'], $client);

        $serverToolTypes = [];
        $hasPtcTool = false;
        if (! empty($payload['tools'])) {
            [$payload['tools'], $serverToolTypes, $hasPtcTool] = $this->toolNormaliser->normalise($payload['tools']);
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

        $betaHeaders = $this->collectBetaHeaders(array_values(array_unique($betaFeatures)));

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

    private function enforcePayloadSizeLimit(string $jsonBody): void
    {
        if (strlen($jsonBody) > self::MAX_PAYLOAD_BYTES) {
            throw PayloadBuildException::requestTooLarge(
                'Payload exceeds maximum size of 32MB'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $capabilities
     * @return list<string>
     */
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

        return array_values(array_unique($features));
    }

    /**
     * @param  list<string>  $features
     * @return list<string>
     */
    private function collectBetaHeaders(array $features): array
    {
        $headers = [];

        foreach ($features as $feature) {
            $header = $this->betaHeaderMap[$feature] ?? null;

            if ($header !== null) {
                $headers[] = $header;
            }
        }

        return array_values(array_unique($headers));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function serialize(array $payload): string
    {
        return json_encode($payload, self::JSON_OPTIONS);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return list<array<string, mixed>>
     */
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

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function requireContextManagementSupport(array $capabilities, string $alias): void
    {
        if (! ($capabilities['supports_context_management_edits'] ?? true)) {
            throw PayloadBuildException::invalidRequest("Context management edits not supported on model $alias");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
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

    /**
     * @param  array<string, mixed>  $payload
     */
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasMcpServers(array $payload): bool
    {
        return ! empty($payload['mcp_servers']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasComputerUseTool(array $payload): bool
    {
        return array_any(
            $payload['tools'] ?? [],
            static fn (mixed $tool): bool => ($tool['type'] ?? '') === ToolTypeCatalog::COMPUTER,
        );
    }
}
