<?php

declare(strict_types=1);

namespace App\Components\Messaging\DTO;

use App\Models\Client;
use DateTimeImmutable;

final readonly class MessageRequestInput
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  string[]  $additionalFeatures
     */
    public function __construct(
        public Client $client,
        public array $payload,
        public string $gatewayRequestId,
        public DateTimeImmutable $startedAt,
        public array $additionalFeatures = [],
    ) {}
}
