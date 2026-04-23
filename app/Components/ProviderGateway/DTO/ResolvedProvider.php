<?php

namespace App\Components\ProviderGateway\DTO;

readonly class ResolvedProvider
{
    public function __construct(
        public string $providerName,
        public string $modelName,
        public string $endpoint,
        public string $apiKey,
        public bool $isFallback = false,
    ) {}

    public function toArray(): array
    {
        return [
            'providerName' => $this->providerName,
            'modelName' => $this->modelName,
            'endpoint' => $this->endpoint,
            'apiKey' => $this->apiKey,
            'isFallback' => $this->isFallback,
        ];
    }
}
