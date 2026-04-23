<?php

declare(strict_types=1);

namespace App\Components\Validation\Rules;

use App\Components\Validation\DTO\ValidationError;

final class ThinkingCompatibilityRule
{
    public function check(array $payload): ?ValidationError
    {
        $thinking = $payload['thinking'] ?? null;

        if ($thinking === null) {
            return null;
        }

        $type = $thinking['type'] ?? null;
        $model = $payload['model'] ?? '';
        $capabilities = config("llm.claude.model_capabilities.$model", []);

        if ($type === 'adaptive') {
            if (! ($capabilities['supports_adaptive_thinking'] ?? false)) {
                return new ValidationError('/thinking', 'adaptive_not_supported', "Adaptive thinking not supported on $model");
            }

            $effort = $thinking['effort'] ?? null;
            if ($effort !== null && ! in_array($effort, ['low', 'medium', 'high'], true)) {
                return new ValidationError('/thinking/effort', 'invalid_effort', "Invalid thinking effort: '$effort' — must be low, medium, or high");
            }
        }

        if ($type === 'enabled') {
            if (! ($capabilities['supports_thinking'] ?? false)) {
                return new ValidationError('/thinking', 'thinking_not_supported', "$model does not support extended thinking");
            }

            $budget = $thinking['budget_tokens'] ?? null;
            $maxTokens = $payload['max_tokens'] ?? null;
            $requiresBelowMax = ! ($capabilities['supports_adaptive_thinking'] ?? false);

            if ($requiresBelowMax && $budget !== null && $maxTokens !== null && $budget >= $maxTokens) {
                return new ValidationError(
                    '/thinking/budget_tokens',
                    'budget_exceeds_max_tokens',
                    "budget_tokens ($budget) must be less than max_tokens ($maxTokens) on $model",
                );
            }
        }

        if ($type === 'adaptive' || $type === 'enabled') {
            return $this->checkSamplingConstraints($payload);
        }

        return null;
    }

    private function checkSamplingConstraints(array $payload): ?ValidationError
    {
        if (isset($payload['tool_choice'])) {
            $tcType = $payload['tool_choice']['type'] ?? $payload['tool_choice'] ?? null;
            if (! in_array($tcType, ['auto', 'none'], true)) {
                return new ValidationError(
                    '/tool_choice',
                    'tool_choice_with_thinking',
                    "tool_choice must be 'auto' or 'none' when thinking is enabled",
                );
            }
        }

        if (isset($payload['top_p'])) {
            $topP = (float) $payload['top_p'];
            if ($topP < 0.95 || $topP > 1.0) {
                return new ValidationError(
                    '/top_p',
                    'top_p_with_thinking',
                    "top_p must be within [0.95, 1.0] when thinking is enabled, got $topP",
                );
            }
        }

        return null;
    }
}
