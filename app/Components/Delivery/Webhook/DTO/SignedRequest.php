<?php

declare(strict_types=1);

namespace App\Components\Delivery\Webhook\DTO;

final readonly class SignedRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body,
        public array $headers,
    ) {}
}
