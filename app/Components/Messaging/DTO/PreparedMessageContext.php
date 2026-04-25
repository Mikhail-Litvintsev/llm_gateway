<?php

declare(strict_types=1);

namespace App\Components\Messaging\DTO;

use App\Components\Billing\DTO\TokenEstimate;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Models\Client;
use DateTimeImmutable;

final readonly class PreparedMessageContext
{
    /**
     * @param  list<string>  $featuresUsed
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>  $injectedPayload
     */
    public function __construct(
        public string $gatewayRequestId,
        public Client $client,
        public DateTimeImmutable $startedAt,
        public string $modelAlias,
        public string $modelSnapshot,
        public array $featuresUsed,
        public array $rawPayload,
        public array $injectedPayload,
        public BuiltPayload $builtPayload,
        public TokenEstimate $tokenEstimate,
    ) {}
}
