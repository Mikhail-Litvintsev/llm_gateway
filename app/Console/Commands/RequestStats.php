<?php

namespace App\Console\Commands;

use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Models\ApiClient;
use App\Models\RequestLog;
use App\Models\ResponseLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RequestStats extends Command
{
    protected $signature = 'llm:stats
        {--client= : Filter by client name}
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}';

    protected $description = 'Display request statistics and metrics';

    public function handle(): int
    {
        $clientId = null;

        if ($clientName = $this->option('client')) {
            $client = ApiClient::where('name', $clientName)->first();
            if (!$client) {
                $this->error("Client '{$clientName}' not found.");
                return self::FAILURE;
            }
            $clientId = $client->id;
        }

        $from = $this->option('from');
        $to = $this->option('to');

        $query = RequestLog::query()->inPeriod($from, $to);

        if ($clientId) {
            $query->forClient($clientId);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No requests found for the given criteria.');
            return self::SUCCESS;
        }

        $devModeCount = (clone $query)->where('provider_used', 'stub')->count();

        $this->info("Total requests: {$total}");
        $this->info("Dev mode requests: {$devModeCount}");
        $this->newLine();

        // By status
        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $this->info('By status:');
        $statusRows = [];
        foreach (RequestStatus::cases() as $status) {
            $count = $byStatus[$status->value] ?? 0;
            $statusRows[] = [$status->value, $count, $total > 0 ? round($count / $total * 100, 1) . '%' : '0%'];
        }
        $this->table(['Status', 'Count', '%'], $statusRows);

        // By provider
        $byProvider = (clone $query)
            ->whereNotNull('provider_used')
            ->select('provider_used', DB::raw('COUNT(*) as count'))
            ->groupBy('provider_used')
            ->pluck('count', 'provider_used');

        if ($byProvider->isNotEmpty()) {
            $this->info('By provider:');
            $this->table(
                ['Provider', 'Count'],
                $byProvider->map(fn ($count, $provider) => [$provider, $count])->values()->toArray(),
            );
        }

        // Latency
        $latencyStats = ResponseLog::query()
            ->whereIn('request_log_id', (clone $query)->select('id'))
            ->selectRaw('AVG(latency_ms) as avg_ms, MIN(latency_ms) as min_ms, MAX(latency_ms) as max_ms')
            ->first();

        if ($latencyStats->avg_ms !== null) {
            $this->info('Latency (ms):');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Average', round($latencyStats->avg_ms)],
                    ['Min', $latencyStats->min_ms],
                    ['Max', $latencyStats->max_ms],
                ],
            );
        }

        // Errors by type
        $errors = (clone $query)
            ->whereNotNull('error_code')
            ->select('error_code', DB::raw('COUNT(*) as count'))
            ->groupBy('error_code')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'error_code');

        if ($errors->isNotEmpty()) {
            $this->info('Top errors:');
            $this->table(
                ['Error Code', 'Count'],
                $errors->map(fn ($count, $code) => [$code, $count])->values()->toArray(),
            );
        }

        return self::SUCCESS;
    }
}
