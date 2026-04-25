<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Validators;

use App\Components\Claude\DTO\ThinkingSpec;
use App\Components\Claude\Enums\ThinkingMode;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;

final readonly class ThinkingValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $capabilities
     * @param  list<array{code: string, message: string}>  $warnings
     *
     * @throws PayloadBuildException
     */
    public function validate(
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
            ThinkingMode::Adaptive => $this->validateAdaptive($spec, $capabilities, $alias),
            ThinkingMode::Manual => $this->validateManual($spec, $payload, $capabilities, $alias, $warnings),
            default => null,
        };

        $this->validateSamplingCompatibility($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(ThinkingSpec $spec): array
    {
        return match ($spec->mode) {
            ThinkingMode::Adaptive => [
                'type' => 'adaptive',
                'effort' => $spec->effort ?? (string) config('llm.claude.adaptive_thinking.default_effort', 'medium'),
            ],
            ThinkingMode::Manual => array_filter([
                'type' => 'enabled',
                'budget_tokens' => $spec->budgetTokens,
            ], fn ($v): bool => $v !== null),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $capabilities
     *
     * @throws PayloadBuildException
     */
    private function validateAdaptive(ThinkingSpec $spec, array $capabilities, string $alias): void
    {
        if (! ($capabilities['supports_adaptive_thinking'] ?? false)) {
            throw PayloadBuildException::invalidRequest("Adaptive thinking not supported on $alias");
        }

        $effort = $spec->effort ?? (string) config('llm.claude.adaptive_thinking.default_effort', 'medium');
        if (! in_array($effort, ['low', 'medium', 'high'], true)) {
            throw PayloadBuildException::invalidRequest(
                "Invalid thinking effort: '$effort' — must be low, medium, or high"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $capabilities
     * @param  list<array{code: string, message: string}>  $warnings
     *
     * @throws PayloadBuildException
     */
    private function validateManual(
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

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws PayloadBuildException
     */
    private function validateSamplingCompatibility(array $payload): void
    {
        if (isset($payload['tool_choice'])) {
            $tcType = $payload['tool_choice']['type'] ?? $payload['tool_choice'];
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
}
