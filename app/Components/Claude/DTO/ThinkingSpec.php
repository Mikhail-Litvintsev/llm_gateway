<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

use App\Components\Claude\Enums\ThinkingMode;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;

final readonly class ThinkingSpec
{
    public function __construct(
        public ThinkingMode $mode,
        public ?string $effort = null,
        public ?int $budgetTokens = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return new self(ThinkingMode::Off);
        }

        $type = $raw['type'] ?? null;
        $mode = is_string($type) ? ThinkingMode::tryFrom($type) : null;

        if ($mode === null) {
            throw PayloadBuildException::invalidRequest("Unknown thinking type: '$type'");
        }

        return match ($mode) {
            ThinkingMode::Off => new self(ThinkingMode::Off),
            ThinkingMode::Adaptive => new self($mode, effort: $raw['effort'] ?? null),
            ThinkingMode::Manual => new self($mode, budgetTokens: isset($raw['budget_tokens']) ? (int) $raw['budget_tokens'] : null),
        };
    }

    public function isEnabled(): bool
    {
        return $this->mode !== ThinkingMode::Off;
    }
}
