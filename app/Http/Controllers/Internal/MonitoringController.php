<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitoringController extends Controller
{
    public function health(): JsonResponse
    {
        $lastRequest = RequestLog::orderByDesc('created_at')->value('created_at');

        return response()->json([
            'status' => 'ok',
            'queue_size' => DB::table('jobs')->count(),
            'pending_prompts' => PendingPrompt::count(),
            'pending_prompts_expired' => PendingPrompt::expired()->count(),
            'pending_responses' => PendingResponse::count(),
            'pending_responses_failed' => PendingResponse::where('delivery_status', DeliveryStatus::Failed)->count(),
            'last_request_at' => $lastRequest?->toIso8601String(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $clientId = $request->query('client_id');

        $query = RequestLog::query()
            ->inPeriod($from, $to);

        if ($clientId) {
            $query->forClient((int) $clientId);
        }

        $total = $query->count();

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byProvider = (clone $query)
            ->whereNotNull('provider_used')
            ->select('provider_used', DB::raw('COUNT(*) as count'))
            ->groupBy('provider_used')
            ->pluck('count', 'provider_used')
            ->toArray();

        $avgLatency = (int) ResponseLog::query()
            ->whereIn('request_log_id', (clone $query)->select('id'))
            ->avg('latency_ms');

        $failedCount = $byStatus[RequestStatus::Failed->value] ?? 0;
        $errorRate = $total > 0 ? round($failedCount / $total, 3) : 0;

        return response()->json([
            'total_requests' => $total,
            'by_status' => $byStatus,
            'by_provider' => $byProvider,
            'avg_latency_ms' => $avgLatency,
            'error_rate' => $errorRate,
        ]);
    }
}
