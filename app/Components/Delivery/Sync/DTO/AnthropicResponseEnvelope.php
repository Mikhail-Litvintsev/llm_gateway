<?php

declare(strict_types=1);

namespace App\Components\Delivery\Sync\DTO;

final readonly class AnthropicResponseEnvelope
{
    /**
     * @param array<string, string> $anthropicHeaders
     */
    public function __construct(
        public int $httpStatusCode,
        public string $rawBody,
        public array $anthropicHeaders = [],
    ) {}
}
