<?php

declare(strict_types=1);

namespace App\Components\Validation;

use App\Components\Validation\DTO\ValidationError;
use App\Components\Validation\DTO\ValidationResult;
use App\Models\Client;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class MessageRequestValidator
{
    private const RULES_PER_CONTEXT = [
        'sync' => [
            'require_stream'          => false,
            'forbid_stream'           => false,
            'use_max_output_batch'    => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode'         => true,
            'require_callback_url'    => false,
        ],
        'sync_stream' => [
            'require_stream'          => true,
            'forbid_stream'           => false,
            'use_max_output_batch'    => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode'         => true,
            'require_callback_url'    => false,
        ],
        'async_callback' => [
            'require_stream'          => false,
            'forbid_stream'           => false,
            'use_max_output_batch'    => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode'         => true,
            'require_callback_url'    => true,
        ],
        'batch_item' => [
            'require_stream'          => false,
            'forbid_stream'           => true,
            'use_max_output_batch'    => true,
            'allow_service_tier_auto' => false,
            'allow_fast_mode'         => false,
            'require_callback_url'    => false,
        ],
        'session' => [
            'require_stream'          => false,
            'forbid_stream'           => false,
            'use_max_output_batch'    => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode'         => true,
            'require_callback_url'    => false,
            'override_from_session'   => ['model_alias', 'system', 'tools'],
        ],
        'count_tokens' => [
            'require_stream'          => false,
            'forbid_stream'           => true,
            'use_max_output_batch'    => false,
            'allow_service_tier_auto' => true,
            'allow_fast_mode'         => false,
            'require_callback_url'    => false,
            'skip_rate_limit_check'   => true,
            'skip_spend_cap_check'    => true,
        ],
    ];

    public function __construct(
        private readonly Validator $schemaValidator,
    ) {}

    public function validate(array $payload, ValidationContext $ctx, Client $client): ValidationResult
    {
        $errors = [];

        $this->preCheck($payload, $errors);

        if ($errors !== []) {
            return new ValidationResult($errors);
        }

        $this->schemaCheck($payload, $ctx, $errors);
        $this->contextRulesCheck($payload, $ctx, $client, $errors);
        $this->semanticCheck($payload, $errors);

        return new ValidationResult($errors);
    }

    private function preCheck(array $payload, array &$errors): void
    {
        if (!isset($payload['messages'])) {
            $errors[] = new ValidationError('/', 'messages_required', 'Field "messages" is required');
            return;
        }

        if (!isset($payload['model'])) {
            $errors[] = new ValidationError('/', 'model_required', 'Field "model" is required');
            return;
        }

        $aliases = config('llm.claude.model_aliases', []);
        if (!isset($aliases[$payload['model']])) {
            $errors[] = new ValidationError('/model', 'unknown_model_alias', "Unknown model alias: {$payload['model']}");
        }
    }

    private function schemaCheck(array $payload, ValidationContext $ctx, array &$errors): void
    {
        $schemaUri = $ctx === ValidationContext::BatchItem
            ? 'urn:gateway:batch_item'
            : 'urn:gateway:message_request';

        $data = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $result = $this->schemaValidator->validate($data, $schemaUri);

        if (!$result->isValid()) {
            $formatter = new ErrorFormatter();
            $formatted = $formatter->format($result->error());

            foreach ($formatted as $path => $messages) {
                foreach ($messages as $message) {
                    $errors[] = new ValidationError($path, 'schema_violation', $message);
                }
            }
        }
    }

    private function contextRulesCheck(array $payload, ValidationContext $ctx, Client $client, array &$errors): void
    {
        $rules = self::RULES_PER_CONTEXT[$ctx->value] ?? [];

        if (($rules['forbid_stream'] ?? false) && ($payload['stream'] ?? false) === true) {
            $errors[] = new ValidationError('/stream', 'stream_forbidden_in_' . $ctx->value, "Streaming is not allowed in {$ctx->value} context");
        }

        if (($rules['require_stream'] ?? false) && ($payload['stream'] ?? false) !== true) {
            $errors[] = new ValidationError('/stream', 'stream_required', 'Stream must be true for sync_stream context');
        }

        $modelAlias = $payload['model'] ?? '';
        $capabilities = config("llm.claude.model_capabilities.{$modelAlias}", []);

        if ($rules['use_max_output_batch'] ?? false) {
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

        $overrides = $rules['override_from_session'] ?? [];
        foreach ($overrides as $field) {
            $payloadKey = match ($field) {
                'model_alias' => 'model',
                default => $field,
            };
            if (isset($payload[$payloadKey])) {
                $errors[] = new ValidationError("/{$payloadKey}", 'field_overridden_by_session', "Field \"{$payloadKey}\" is managed by session and must not be in payload");
            }
        }
    }

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
            if (!is_array($prevContent)) {
                continue;
            }

            $hasPtcToolUse = false;
            foreach ($prevContent as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                    $hasPtcToolUse = true;
                    break;
                }
            }

            if (!$hasPtcToolUse) {
                continue;
            }

            $currContent = $curr['content'] ?? [];
            if (!is_array($currContent)) {
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
