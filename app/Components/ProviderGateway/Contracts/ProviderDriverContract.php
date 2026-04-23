<?php

namespace App\Components\ProviderGateway\Contracts;

use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\Enums\ProviderName;

interface ProviderDriverContract
{
    public function send(AssembledPayload $payload, ResolvedProvider $provider, int $timeoutSeconds): RawProviderResponse;

    public function name(): ProviderName;
}
