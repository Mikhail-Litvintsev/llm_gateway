<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Models\Client;

final readonly class SendMessageInput
{
    public function __construct(
        public BuiltPayload $payload,
        public Client $client,
        public string $gatewayRequestId,
        public array $featuresUsed,
    ) {}
}
