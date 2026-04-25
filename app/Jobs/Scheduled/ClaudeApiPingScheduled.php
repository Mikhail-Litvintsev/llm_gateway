<?php

declare(strict_types=1);

namespace App\Jobs\Scheduled;

use App\Components\Healthcheck\Enums\HealthStatus;
use App\Components\Routing\WorkspaceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

final class ClaudeApiPingScheduled implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 10;

    public int $tries = 1;

    public function handle(WorkspaceResolver $workspaces): void
    {
        try {
            $workspace = $workspaces->resolveDefault();
        } catch (DecryptException) {
            $this->cacheStatus(HealthStatus::Down, null, 'default workspace api_key cannot be decrypted (likely APP_KEY rotated without re-encryption; see runbook)');

            return;
        }

        if (! $workspace) {
            $this->cacheStatus(HealthStatus::Down, null, 'no default workspace configured');

            return;
        }

        $startMs = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $workspace->apiKey,
                'anthropic-version' => config('llm.claude.anthropic_version'),
            ])
                ->timeout(3)
                ->get(config('llm.claude.endpoints.models'));

            $latencyMs = (int) ((microtime(true) - $startMs) * 1000);
            $status = $response->successful() ? HealthStatus::Ok : HealthStatus::Degraded;
            $error = $response->successful() ? null : "HTTP {$response->status()}";
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $startMs) * 1000);
            $status = HealthStatus::Down;
            $error = $e->getMessage();
        }

        $this->cacheStatus($status, $latencyMs, $error);
    }

    private function cacheStatus(HealthStatus $status, ?int $latencyMs, ?string $error): void
    {
        Redis::connection('cache')->setex('claude:healthcheck:anthropic', 90, json_encode([
            'status' => $status->value,
            'latency_ms' => $latencyMs,
            'checked_at' => now()->toIso8601String(),
            'error' => $error,
        ]));
    }
}
