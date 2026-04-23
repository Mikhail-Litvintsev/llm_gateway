<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\DTO;

final readonly class BuiltPayload
{
    public function __construct(
        public string $jsonBody,
        public array $betaHeaders,
        public string $modelSnapshot,
        public string $modelAlias,
        public int $payloadSizeBytes,
        public array $decodedPayload,
        public array $serverToolTypes = [],
        public array $warnings = [],
    ) {}
}
