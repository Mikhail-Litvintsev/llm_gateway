<?php

declare(strict_types=1);

namespace App\Components\Healthcheck;

use App\Components\Healthcheck\DTO\HealthReport;
use App\Components\Healthcheck\Enums\HealthStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class Healthcheck
{
    /**
     * @phpstan-return HealthReport
     */
    public function report(): HealthReport
    {
        $components = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
            'anthropic' => $this->readAnthropicCached(),
        ];

        $overall = HealthStatus::Ok;
        foreach ($components as $c) {
            if ($c['status'] === HealthStatus::Down) {
                $overall = HealthStatus::Down;
                break;
            }
            if ($c['status'] === HealthStatus::Degraded) {
                $overall = HealthStatus::Degraded;
            }
        }

        return new HealthReport(
            overall: $overall,
            components: $components,
            anthropicLastCheckAt: $components['anthropic']['checked_at'] ?? null,
            anthropicLastStatus: $components['anthropic']['status'] ?? null,
        );
    }

    private function checkDb(): array
    {
        $startMs = microtime(true);
        try {
            DB::connection()->selectOne('SELECT 1');

            return [
                'status' => HealthStatus::Ok,
                'latency_ms' => (int) ((microtime(true) - $startMs) * 1000),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => HealthStatus::Down,
                'latency_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkRedis(): array
    {
        $startMs = microtime(true);
        try {
            Redis::connection('cache')->ping();

            return [
                'status' => HealthStatus::Ok,
                'latency_ms' => (int) ((microtime(true) - $startMs) * 1000),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => HealthStatus::Down,
                'latency_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function readAnthropicCached(): array
    {
        $cached = Redis::connection('cache')->get('claude:healthcheck:anthropic');

        if (! $cached) {
            return [
                'status' => HealthStatus::Degraded,
                'latency_ms' => null,
                'error' => 'no recent ping result',
                'checked_at' => null,
            ];
        }

        $decoded = json_decode($cached, true);

        return [
            'status' => HealthStatus::from($decoded['status']),
            'latency_ms' => $decoded['latency_ms'] ?? null,
            'error' => $decoded['error'] ?? null,
            'checked_at' => isset($decoded['checked_at']) ? Carbon::parse($decoded['checked_at']) : null,
        ];
    }
}
