<?php

namespace App\Components\ProviderGateway;

use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\Enums\ProviderName;
use App\Components\RequestPipeline\DTO\ProviderConfig;
use App\Components\RequestPipeline\Exceptions\ValidationException;

class ProviderResolver
{
    public function resolve(?ProviderConfig $config): ResolvedProvider
    {
        $providerName = $config?->name;
        $modelName = $config?->model;

        // Only model specified — detect provider from model name
        if (!$providerName && $modelName) {
            $detected = ProviderName::fromModel($modelName);
            $providerName = $detected?->value
                ?? throw new ValidationException('MODEL_UNKNOWN', "Cannot determine provider for model: $modelName");
        }

        // Nothing specified — default to claude
        if (!$providerName) {
            $providerName = 'claude';
        }

        $providerConf = config("llm.providers.$providerName")
            ?? throw new ValidationException('PROVIDER_UNKNOWN', "Provider '$providerName' is not registered.");

        if (!$modelName) {
            $modelName = $providerConf['default_model'];
        }

        return new ResolvedProvider(
            providerName: $providerName,
            modelName: $modelName,
            endpoint: $providerConf['endpoint'],
            apiKey: $providerConf['api_key'] ?? '',
        );
    }
}
