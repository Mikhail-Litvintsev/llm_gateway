<?php

declare(strict_types=1);

namespace App\Components\Validation\Rules;

use App\Components\Claude\Enums\ServerToolFeature;
use App\Components\Claude\ToolTypeCatalog;
use App\Components\Pricing\CodeExecutionUsageTracker;
use App\Components\Validation\Exceptions\FeatureNotAllowedException;
use App\Components\Validation\Exceptions\FeatureQuotaExhaustedException;
use App\Models\Client;

final readonly class ServerFeaturesRule
{
    public function __construct(
        private CodeExecutionUsageTracker $codeExecTracker,
    ) {}

    public function check(array $payload, Client $client): void
    {
        $allowedFeatures = $client->allowed_features ?? [];

        foreach ($payload['tools'] ?? [] as $tool) {
            $type = $tool['type'] ?? null;

            if (! is_string($type)) {
                continue;
            }

            $feature = ToolTypeCatalog::featureFor($type);

            if ($feature === null) {
                continue;
            }

            if (empty($allowedFeatures[$feature->value])) {
                throw new FeatureNotAllowedException($feature->value);
            }

            if ($feature === ServerToolFeature::CodeExecution
                && ! $this->codeExecTracker->hasFreeHoursRemaining((int) $client->workspace_id)) {
                throw new FeatureQuotaExhaustedException;
            }
        }
    }
}
