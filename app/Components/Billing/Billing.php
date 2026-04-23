<?php

declare(strict_types=1);

namespace App\Components\Billing;

use App\Components\Billing\DTO\SpendPreCheckResult;
use App\Components\Billing\DTO\SpendRecordResult;
use App\Components\Billing\Enums\SpendGateDecision;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

final readonly class Billing
{
    public function __construct(
        private UsageTracker $usageTracker,
    ) {}

    /**
     * @phpDoc Soft-cap pre-check from hydrated model (no SQL).
     * Optionally consults Redis hard cap if client has hard_cap_enforcement enabled.
     */
    public function preCheck(Client $client): SpendPreCheckResult
    {
        $cap = $client->monthly_spend_cap_usd !== null
            ? (float) $client->monthly_spend_cap_usd
            : null;
        $currentSpend = (float) $client->current_month_spend_usd;

        if ($cap === null) {
            return new SpendPreCheckResult(
                decision: SpendGateDecision::AllowedUnlimited,
                currentSpendUsd: $currentSpend,
                capUsd: null,
            );
        }

        if ($this->hasHardCapEnforcement($client)) {
            $redisSpend = $this->usageTracker->currentSpend($client);
            if ($redisSpend >= $cap) {
                return new SpendPreCheckResult(
                    decision: SpendGateDecision::HardCapExceeded,
                    currentSpendUsd: max($currentSpend, $redisSpend),
                    capUsd: $cap,
                );
            }
        }

        if ($currentSpend >= $cap) {
            return new SpendPreCheckResult(
                decision: SpendGateDecision::SoftCapExceeded,
                currentSpendUsd: $currentSpend,
                capUsd: $cap,
            );
        }

        return new SpendPreCheckResult(
            decision: SpendGateDecision::AllowedWithinCap,
            currentSpendUsd: $currentSpend,
            capUsd: $cap,
        );
    }

    /**
     * @phpDoc Record spend after a successful provider response.
     * Atomic UPDATE + SELECT on clients table. Optionally syncs Redis counter.
     */
    public function recordSpend(Client $client, float $costUsd): SpendRecordResult
    {
        DB::table('clients')
            ->where('id', $client->id)
            ->increment('current_month_spend_usd', $costUsd, [
                'updated_at' => now(),
            ]);

        $newTotal = (float) DB::table('clients')
            ->where('id', $client->id)
            ->value('current_month_spend_usd');

        if ($this->hasHardCapEnforcement($client)) {
            $this->usageTracker->commit($client, $costUsd);
        }

        $cap = $client->monthly_spend_cap_usd !== null
            ? (float) $client->monthly_spend_cap_usd
            : null;

        $remainingUsd = $cap !== null ? max(0.0, $cap - $newTotal) : null;
        $capJustExceeded = $cap !== null && $newTotal >= $cap && ($newTotal - $costUsd) < $cap;

        return new SpendRecordResult(
            newTotalUsd: $newTotal,
            remainingUsd: $remainingUsd,
            capJustExceeded: $capJustExceeded,
        );
    }

    private function hasHardCapEnforcement(Client $client): bool
    {
        $features = $client->allowed_features ?? [];

        return !empty($features['hard_cap_enforcement']);
    }
}
