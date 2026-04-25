<?php

declare(strict_types=1);

namespace App\Components\Authorization;

use App\Components\Authorization\DTO\AuthorizationResult;
use App\Components\Authorization\Enums\AuthorizationDenialReason;
use App\Models\Client;

final class Authorization
{
    private const array DEFAULT_ALLOW_FEATURES = [
        'prompt_caching',
        'citations',
    ];

    /**
     * @param  Client  $client  Hydrated Client model (no DB queries issued).
     * @param  string  $modelAlias  Resolved model alias (e.g. "claude-sonnet-4-6").
     * @param  list<string>  $featuresUsed  Flat list of feature keys extracted from the validated payload.
     *                                      Valid keys: thinking, web_search, code_execution, computer_use, bash, text_editor,
     *                                      priority_tier, citations, prompt_caching, structured_outputs, batch.
     */
    public function authorize(
        Client $client,
        string $modelAlias,
        array $featuresUsed,
    ): AuthorizationResult {
        $allowedFeatures = $client->allowed_features ?? [];

        $modelCheck = $this->checkModelWhitelist($allowedFeatures, $modelAlias);
        if (! $modelCheck->allowed) {
            return $modelCheck;
        }

        $featureCheck = $this->checkFeatureWhitelist($allowedFeatures, $featuresUsed);
        if (! $featureCheck->allowed) {
            return $featureCheck;
        }

        return $this->checkSpendCap($client);
    }

    /**
     * @param  array<string, mixed>  $allowedFeatures
     */
    private function checkModelWhitelist(array $allowedFeatures, string $modelAlias): AuthorizationResult
    {
        $models = $allowedFeatures['models'] ?? [];

        if (empty($models)) {
            return AuthorizationResult::allow();
        }

        if (! in_array($modelAlias, $models, true)) {
            return AuthorizationResult::deny(
                reason: AuthorizationDenialReason::ModelNotAllowed,
                message: "Model '$modelAlias' is not allowed for this API key.",
            );
        }

        return AuthorizationResult::allow();
    }

    /**
     * @param  array<string, mixed>  $allowedFeatures
     * @param  list<string>  $featuresUsed
     */
    private function checkFeatureWhitelist(array $allowedFeatures, array $featuresUsed): AuthorizationResult
    {
        foreach ($featuresUsed as $feature) {
            if (! $this->isFeatureAllowed($allowedFeatures, $feature)) {
                return AuthorizationResult::deny(
                    reason: AuthorizationDenialReason::FeatureNotAllowed,
                    message: "Feature '$feature' is not allowed for this API key.",
                    deniedFeature: $feature,
                );
            }
        }

        return AuthorizationResult::allow();
    }

    /**
     * @param  array<string, mixed>  $allowedFeatures
     */
    private function isFeatureAllowed(array $allowedFeatures, string $feature): bool
    {
        if (array_key_exists($feature, $allowedFeatures)) {
            return (bool) $allowedFeatures[$feature];
        }

        return in_array($feature, self::DEFAULT_ALLOW_FEATURES, true);
    }

    private function checkSpendCap(Client $client): AuthorizationResult
    {
        if ($client->monthly_spend_cap_usd === null) {
            return AuthorizationResult::allow();
        }

        if ((float) $client->current_month_spend_usd >= (float) $client->monthly_spend_cap_usd) {
            return AuthorizationResult::deny(
                reason: AuthorizationDenialReason::MonthlySpendCapExceeded,
                message: 'Monthly spend cap exceeded. Please contact your administrator to increase the limit.',
            );
        }

        return AuthorizationResult::allow();
    }
}
