<?php

declare(strict_types=1);

namespace App\Components\Billing;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

class UsageTracker
{
    /**
     * Reserve spend against the Redis hard cap. Returns false if reservation would exceed cap.
     * On failure, the increment is rolled back via DECRBYFLOAT.
     */
    public function reserve(Client $client, float $costUsd): bool
    {
        $cap = $this->getCap($client);

        if ($cap === null) {
            return true;
        }

        $key = $this->redisKey($client);
        $connection = Redis::connection('cache');

        $newTotal = (float) $connection->incrbyfloat($key, $costUsd);
        $this->ensureTtl($connection, $key);

        if ($newTotal > $cap) {
            $connection->incrbyfloat($key, -$costUsd);

            return false;
        }

        return true;
    }

    /**
     * Commit spend to Redis counter without reservation check.
     * Used on the soft-cap path to keep Redis in approximate sync for observability.
     */
    public function commit(Client $client, float $costUsd): void
    {
        $key = $this->redisKey($client);
        $connection = Redis::connection('cache');

        $connection->incrbyfloat($key, $costUsd);
        $this->ensureTtl($connection, $key);
    }

    /**
     * Read the current Redis counter value for a client's monthly spend.
     */
    public function currentSpend(Client $client): float
    {
        $value = Redis::connection('cache')->get($this->redisKey($client));

        return $value !== null ? (float) $value : 0.0;
    }

    private function redisKey(Client $client): string
    {
        $prefix = config('llm.billing.hard_cap.redis_key_prefix', 'llm:billing:spend:');
        $month = CarbonImmutable::now('UTC')->format('Y-m');

        return $prefix.$client->id.':'.$month;
    }

    private function getCap(Client $client): ?float
    {
        return $client->monthly_spend_cap_usd !== null
            ? (float) $client->monthly_spend_cap_usd
            : null;
    }

    private function ensureTtl($connection, string $key): void
    {
        $ttl = $connection->ttl($key);

        if ($ttl < 0) {
            $endOfMonth = CarbonImmutable::now('UTC')
                ->endOfMonth()
                ->addSeconds(3600);

            $seconds = (int) CarbonImmutable::now('UTC')->diffInSeconds($endOfMonth);
            $connection->expire($key, $seconds);
        }
    }
}
