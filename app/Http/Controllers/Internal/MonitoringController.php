<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Components\Healthcheck\Enums\HealthStatus;
use App\Components\Healthcheck\Healthcheck;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class MonitoringController extends Controller
{
    public function health(Healthcheck $healthcheck): JsonResponse
    {
        $report = $healthcheck->report();

        $httpStatus = match ($report->overall) {
            HealthStatus::Ok => 200,
            HealthStatus::Degraded => 200,
            HealthStatus::Down => 503,
        };

        return response()->json([
            'status' => $report->overall->value,
            'components' => array_map(fn (array $c) => [
                'status' => $c['status']->value,
                'latency_ms' => $c['latency_ms'],
                'error' => $c['error'],
            ], $report->components),
            'anthropic_last_check_at' => $report->anthropicLastCheckAt?->toIso8601String(),
            'anthropic_last_status' => $report->anthropicLastStatus?->value,
        ], $httpStatus);
    }

    public function stats(ClaudeRateLimitTracker $tracker): JsonResponse
    {
        return response()->json([
            'queues' => [
                'high' => Queue::size('high'),
                'default' => Queue::size('default'),
                'low' => Queue::size('low'),
            ],
            'async_pending_counts' => DB::table('async_pending')
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->all(),
            'current_usage' => [],
            'top_spenders_month' => DB::table('clients')
                ->orderByDesc('current_month_spend_usd')
                ->limit(5)
                ->get(['id', 'name', 'current_month_spend_usd'])
                ->all(),
        ]);
    }
}
