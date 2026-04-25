<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;

final readonly class PrefillCompatibilityEnforcer
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $capabilities
     *
     * @throws PayloadBuildException
     */
    public function enforce(array $payload, array $capabilities, string $alias): void
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
}
