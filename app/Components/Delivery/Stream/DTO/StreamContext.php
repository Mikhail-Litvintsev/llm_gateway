<?php

declare(strict_types=1);

namespace App\Components\Delivery\Stream\DTO;

use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Models\Client;
use Closure;

final readonly class StreamContext
{
    public function __construct(
        public BuiltPayload $payload,
        public Client $client,
        public string $gatewayRequestId,
        public array $featuresUsed,
        public Closure $onComplete,
    ) {}
}
