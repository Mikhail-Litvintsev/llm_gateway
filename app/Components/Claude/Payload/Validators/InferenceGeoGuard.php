<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Models\Client;

final readonly class InferenceGeoGuard
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws PayloadBuildException
     */
    public function enforce(array $payload, Client $client): void
    {
        $inferenceGeo = $payload['inference_geo'] ?? null;

        if ($inferenceGeo === null) {
            return;
        }

        $clientGeo = $client->inference_geo ?? null;
        $allowedFeatures = $client->allowed_features ?? [];

        if ($clientGeo !== $inferenceGeo && ! ($allowedFeatures['inference_geo_override'] ?? false)) {
            throw PayloadBuildException::invalidRequest(
                "Inference geo '$inferenceGeo' is not allowed for this client"
            );
        }
    }
}
