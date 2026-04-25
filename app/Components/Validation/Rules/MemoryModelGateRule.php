<?php

declare(strict_types=1);

namespace App\Components\Validation\Rules;

use App\Components\Claude\ToolTypeCatalog;
use App\Components\Validation\DTO\ValidationError;

final class MemoryModelGateRule
{
    private const array BLOCKED_MODELS = ['claude-opus'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function check(array $payload): ?ValidationError
    {
        $hasMemory = array_any(
            $payload['tools'] ?? [],
            fn (mixed $tool): bool => is_array($tool) && ($tool['type'] ?? '') === ToolTypeCatalog::MEMORY,
        );

        if (! $hasMemory) {
            return null;
        }

        $model = $payload['model'] ?? '';

        if (in_array($model, self::BLOCKED_MODELS, true)) {
            return new ValidationError(
                '/tools',
                'memory_model_gate',
                "Memory tool is not supported on $model (supported: Sonnet 4.5/4, Haiku 4.5, Opus 4.1/4)",
            );
        }

        return null;
    }
}
