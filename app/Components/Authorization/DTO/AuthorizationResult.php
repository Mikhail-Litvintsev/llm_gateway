<?php

declare(strict_types=1);

namespace App\Components\Authorization\DTO;

use App\Components\Authorization\Enums\AuthorizationDenialReason;

final readonly class AuthorizationResult
{
    public function __construct(
        public bool $allowed,
        public ?AuthorizationDenialReason $reason = null,
        public ?string $deniedFeature = null,
        public ?string $message = null,
    ) {}

    public static function allow(): self
    {
        return new self(allowed: true);
    }

    public static function deny(
        AuthorizationDenialReason $reason,
        string $message,
        ?string $deniedFeature = null,
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
            deniedFeature: $deniedFeature,
            message: $message,
        );
    }
}
