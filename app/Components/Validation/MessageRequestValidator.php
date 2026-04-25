<?php

declare(strict_types=1);

namespace App\Components\Validation;

use App\Components\Validation\DTO\ValidationError;
use App\Components\Validation\DTO\ValidationResult;
use App\Components\Validation\Enums\ServiceTier;
use App\Components\Validation\Enums\Speed;
use App\Components\Validation\Rules\CitationsConsistencyRule;
use App\Components\Validation\Rules\MemoryModelGateRule;
use App\Components\Validation\Rules\PtcContractRule;
use App\Components\Validation\Rules\SearchResultBlockRule;
use App\Components\Validation\Rules\ServerFeaturesRule;
use App\Components\Validation\Rules\ThinkingCompatibilityRule;
use App\Models\Client;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

class MessageRequestValidator
{
    private const RULES_PER_CONTEXT = [
        'sync' => [
            'require_stream' => false,
            'forbid_stream' => false,
            'use_max_output_batch' => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode' => true,
            'require_callback_url' => false,
        ],
        'sync_stream' => [
            'require_stream' => true,
            'forbid_stream' => false,
            'use_max_output_batch' => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode' => true,
            'require_callback_url' => false,
        ],
        'async_callback' => [
            'require_stream' => false,
            'forbid_stream' => false,
            'use_max_output_batch' => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode' => true,
            'require_callback_url' => true,
        ],
        'batch_item' => [
            'require_stream' => false,
            'forbid_stream' => true,
            'use_max_output_batch' => true,
            'allow_service_tier_auto' => false,
            'allow_fast_mode' => false,
            'require_callback_url' => false,
        ],
        'session' => [
            'require_stream' => false,
            'forbid_stream' => false,
            'use_max_output_batch' => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode' => true,
            'require_callback_url' => false,
        ],
        'count_tokens' => [
            'require_stream' => false,
            'forbid_stream' => true,
            'use_max_output_batch' => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode' => false,
            'require_callback_url' => false,
            'skip_rate_limit_check' => true,
            'skip_spend_cap_check' => true,
        ],
    ];

    public function __construct(
        private readonly Validator $schemaValidator,
        private readonly ?ServerFeaturesRule $serverFeaturesRule = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(array $payload, ValidationContext $ctx, Client $client): ValidationResult
    {
        $errors = [];
        $messagePayload = $ctx === ValidationContext::BatchItem
            ? ($payload['params'] ?? $payload)
            : $payload;

        $this->preCheck($messagePayload, $errors);

        if ($errors !== []) {
            return new ValidationResult($errors);
        }

        $this->schemaCheck($payload, $ctx, $errors);
        $this->contextRulesCheck($messagePayload, $ctx, $client, $errors);
        $this->semanticCheck($messagePayload, $errors);
        $this->phase4Rules($messagePayload, $ctx, $client, $errors);

        return new ValidationResult($errors);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ValidationError>  $errors
     */
    private function preCheck(array $payload, array &$errors): void
    {
        if (! isset($payload['messages'])) {
            $errors[] = new ValidationError('/', 'messages_required', 'Field "messages" is required');

            return;
        }

        if (! isset($payload['model'])) {
            $errors[] = new ValidationError('/', 'model_required', 'Field "model" is required');

            return;
        }

        $aliases = config('llm.claude.model_aliases', []);
        if (! isset($aliases[$payload['model']])) {
            $errors[] = new ValidationError('/model', 'unknown_model_alias', "Unknown model alias: {$payload['model']}");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ValidationError>  $errors
     */
    private function schemaCheck(array $payload, ValidationContext $ctx, array &$errors): void
    {
        $schemaUri = $ctx === ValidationContext::BatchItem
            ? 'urn:gateway:batch_item'
            : 'urn:gateway:message_request';

        $data = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $result = $this->schemaValidator->validate($data, $schemaUri);

        if (! $result->isValid()) {
            $error = $result->error();
            if ($error === null) {
                return;
            }

            $formatter = new ErrorFormatter;
            $formatted = $formatter->format($error);

            foreach ($formatted as $path => $messages) {
                foreach ($messages as $message) {
                    $errors[] = new ValidationError($path, 'schema_violation', $message);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ValidationError>  $errors
     */
    private function contextRulesCheck(array $payload, ValidationContext $ctx, Client $client, array &$errors): void
    {
        $rules = self::RULES_PER_CONTEXT[$ctx->value];

        if ($rules['forbid_stream'] === true && ($payload['stream'] ?? false) === true) {
            $errors[] = new ValidationError('/stream', 'stream_forbidden_in_'.$ctx->value, "Streaming is not allowed in {$ctx->value} context");
        }

        if ($rules['require_stream'] === true && ($payload['stream'] ?? false) !== true) {
            $errors[] = new ValidationError('/stream', 'stream_required', 'Stream must be true for sync_stream context');
        }

        $modelAlias = $payload['model'] ?? '';
        $capabilities = config("llm.claude.model_capabilities.{$modelAlias}", []);

        if ($rules['use_max_output_batch'] === true) {
            $maxOutput = $capabilities['max_output_batch'] ?? PHP_INT_MAX;
            $requestedMax = $payload['max_tokens'] ?? 0;
            if ($requestedMax > $maxOutput) {
                $errors[] = new ValidationError('/max_tokens', 'max_tokens_exceeds_batch_limit', "max_tokens {$requestedMax} exceeds batch limit {$maxOutput} for {$modelAlias}");
            }
        }

        if (isset($payload['thinking']['budget_tokens']) && $capabilities) {
            $maxOutput = $capabilities['max_output'] ?? PHP_INT_MAX;
            if ($payload['thinking']['budget_tokens'] > $maxOutput) {
                $errors[] = new ValidationError('/thinking/budget_tokens', 'thinking_budget_exceeds_max_output', "thinking.budget_tokens exceeds model max_output of {$maxOutput}");
            }
        }

        if ($modelAlias === 'claude-opus' && ($capabilities['supports_prefill'] ?? true) === false) {
            $messages = $payload['messages'] ?? [];
            $lastMessage = end($messages);
            if ($lastMessage && ($lastMessage['role'] ?? '') === 'assistant') {
                $errors[] = new ValidationError('/messages', 'opus_prefill_not_supported', 'Opus 4.6 does not support assistant prefill (last message cannot be role=assistant)');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ValidationError>  $errors
     */
    private function phase4Rules(array $payload, ValidationContext $ctx, Client $client, array &$errors): void
    {
        $this->serverFeaturesRule?->check($payload, $client);

        $serviceTierRaw = $payload['service_tier'] ?? config('llm.claude.service_tier.default', 'standard_only');
        $serviceTier = ServiceTier::tryFrom($serviceTierRaw);
        if ($serviceTier === null) {
            $errors[] = new ValidationError('/service_tier', 'service_tier_invalid', "Invalid service_tier value: '$serviceTierRaw'");

            return;
        }
        if ($serviceTier === ServiceTier::Auto) {
            $allowedFeatures = $client->allowed_features ?? [];
            if (! ($allowedFeatures['priority_tier'] ?? false)) {
                $errors[] = new ValidationError('/service_tier', 'priority_tier_not_enabled', 'Priority Tier feature is not enabled for this client');

                return;
            }
        }

        $inferenceGeo = $payload['inference_geo'] ?? $client->inference_geo ?? null;
        if ($inferenceGeo !== null) {
            $allowed = config('llm.claude.inference_geo.allowed', []);
            if (! in_array($inferenceGeo, $allowed, true)) {
                $errors[] = new ValidationError('/inference_geo', 'inference_geo_invalid', "Invalid inference_geo value: '$inferenceGeo'");

                return;
            }
        }

        $speedRaw = $payload['speed'] ?? null;
        if ($speedRaw !== null) {
            $speed = Speed::tryFrom($speedRaw);
            if ($speed === null) {
                $errors[] = new ValidationError('/speed', 'speed_invalid', "Invalid speed value: '$speedRaw'");

                return;
            }
            if ($speed === Speed::Fast) {
                $allowedFeatures = $client->allowed_features ?? [];
                if (! ($allowedFeatures['fast_mode'] ?? false)) {
                    $errors[] = new ValidationError('/speed', 'fast_mode_not_enabled', 'Fast mode is not enabled for this client');

                    return;
                }
                $modelAlias = $payload['model'] ?? '';
                $capabilities = config("llm.claude.model_capabilities.$modelAlias", []);
                if (! ($capabilities['supports_fast_mode'] ?? false)) {
                    $errors[] = new ValidationError('/speed', 'fast_mode_model_unsupported', "Fast mode is not supported on model $modelAlias");

                    return;
                }
                if ($ctx === ValidationContext::BatchItem) {
                    $errors[] = new ValidationError('/speed', 'fast_mode_batch_incompatible', 'Fast mode is incompatible with Batch API');

                    return;
                }
                if ($serviceTier === ServiceTier::Auto) {
                    $errors[] = new ValidationError('/speed', 'fast_mode_priority_incompatible', 'Fast mode is incompatible with priority service tier');

                    return;
                }
            }
        }

        if (! empty($payload['mcp_servers'])) {
            $allowedFeatures = $client->allowed_features ?? [];
            if (! ($allowedFeatures['mcp_connector'] ?? false)) {
                $errors[] = new ValidationError('/mcp_servers', 'mcp_connector_not_enabled', 'MCP connector is not enabled for this client');

                return;
            }
        }

        if (! empty($payload['skills'])) {
            $allowedFeatures = $client->allowed_features ?? [];
            if (! ($allowedFeatures['skills'] ?? false)) {
                $errors[] = new ValidationError('/skills', 'skills_not_enabled', 'Skills feature is not enabled for this client');

                return;
            }
            $hasCodeExecution = array_any(
                $payload['tools'] ?? [],
                static fn (mixed $tool): bool => is_string($tool['type'] ?? null) && str_starts_with($tool['type'], 'code_execution'),
            );
            if (! $hasCodeExecution) {
                $errors[] = new ValidationError('/skills', 'skills_require_code_execution', 'Skills require code_execution tool to be present in the request');

                return;
            }
        }

        $memoryGate = (new MemoryModelGateRule)->check($payload);
        if ($memoryGate !== null) {
            $errors[] = $memoryGate;

            return;
        }

        $searchResult = (new SearchResultBlockRule)->check($payload);
        if ($searchResult !== null) {
            $errors[] = $searchResult;

            return;
        }

        $citations = (new CitationsConsistencyRule)->check($payload);
        if ($citations !== null) {
            $errors[] = $citations;

            return;
        }

        $thinking = (new ThinkingCompatibilityRule)->check($payload);
        if ($thinking !== null) {
            $errors[] = $thinking;

            return;
        }

        $ptc = (new PtcContractRule)->check($payload);
        if ($ptc !== null) {
            $errors[] = $ptc;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ValidationError>  $errors
     */
    private function semanticCheck(array $payload, array &$errors): void
    {
        $messages = $payload['messages'] ?? [];

        for ($i = 1; $i < count($messages); $i++) {
            $prev = $messages[$i - 1];
            $curr = $messages[$i];

            if (($curr['role'] ?? '') !== 'user') {
                continue;
            }

            $prevContent = $prev['content'] ?? [];
            if (! is_array($prevContent)) {
                continue;
            }

            $hasPtcToolUse = false;
            foreach ($prevContent as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                    $hasPtcToolUse = true;
                    break;
                }
            }

            if (! $hasPtcToolUse) {
                continue;
            }

            $currContent = $curr['content'] ?? [];
            if (! is_array($currContent)) {
                $errors[] = new ValidationError("/messages/{$i}/content", 'ptc_user_message_must_be_tool_results_only', 'User message following tool_use must contain only tool_result blocks');

                continue;
            }

            foreach ($currContent as $j => $block) {
                if (is_array($block) && ($block['type'] ?? '') !== 'tool_result') {
                    $errors[] = new ValidationError("/messages/{$i}/content/{$j}", 'ptc_user_message_must_be_tool_results_only', 'User message following tool_use must contain only tool_result blocks');
                    break;
                }
            }
        }
    }
}
