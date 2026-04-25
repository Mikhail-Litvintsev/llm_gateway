<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;

final readonly class MaxTokensEnforcer
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $capabilities
     *
     * @throws PayloadBuildException
     */
    public function enforce(array $payload, array $capabilities, string $alias): void
    {
        $maxTokens = $payload['max_tokens'] ?? null;
        $maxOutput = $capabilities['max_output'] ?? null;

        if ($maxTokens !== null && $maxOutput !== null && $maxTokens > $maxOutput) {
            throw PayloadBuildException::invalidRequest(
                "max_tokens ($maxTokens) exceeds model $alias maximum output ($maxOutput)"
            );
        }
    }
}
