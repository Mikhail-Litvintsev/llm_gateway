<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Models\Client;

final readonly class ServiceTierGuard
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws PayloadBuildException
     */
    public function enforce(array $payload, Client $client): void
    {
        $serviceTier = $payload['service_tier'] ?? null;

        if ($serviceTier !== 'priority') {
            return;
        }

        $allowedFeatures = $client->allowed_features ?? [];

        if (! ($allowedFeatures['priority_tier'] ?? false)) {
            throw PayloadBuildException::permissionError(
                'Client is not authorized to use priority service tier'
            );
        }
    }
}
