<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ClientShow extends Command
{
    protected $signature = 'client:show {client_id : Client ID}';

    protected $description = 'Show client details and usage stats (no secrets)';

    /**
     * Display client info and aggregated usage statistics.
     */
    public function handle(): int
    {
        $client = Client::find($this->argument('client_id'));

        if (! $client) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $monthStart = now()->startOfMonth()->toDateTimeString();

        $monthlyStats = DB::table('requests')
            ->where('client_id', $client->id)
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw("SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed_requests")
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_latency_seconds')
            ->first();

        $monthlyCost = DB::table('requests')
            ->join('request_usage', 'requests.request_id', '=', 'request_usage.request_id')
            ->where('requests.client_id', $client->id)
            ->where('requests.created_at', '>=', $monthStart)
            ->sum('request_usage.cost_usd');

        $topModels = DB::table('requests')
            ->where('client_id', $client->id)
            ->where('created_at', '>=', $monthStart)
            ->groupBy('model_alias')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(5)
            ->selectRaw('model_alias, COUNT(*) as count')
            ->pluck('count', 'model_alias');

        $this->info("Client: id={$client->id} name=\"{$client->name}\"");
        $this->newLine();

        $features = $client->allowed_features ?? [];
        $featuresDisplay = empty($features) ? 'default' : implode(', ', array_keys(array_filter($features)));

        $capUsd = $client->monthly_spend_cap_usd !== null ? '$'.number_format((float) $client->monthly_spend_cap_usd, 2) : 'unlimited';
        $spentUsd = '$'.number_format((float) $monthlyCost, 4);
        $remaining = $client->monthly_spend_cap_usd !== null
            ? '$'.number_format(max(0, (float) $client->monthly_spend_cap_usd - (float) $monthlyCost), 4)
            : 'n/a';

        $this->table(
            ['Property', 'Value'],
            [
                ['Workspace ID', (string) ($client->workspace_id ?? 'none')],
                ['Rate Limit (RPM)', (string) ($client->rate_limit_rpm ?? 'unlimited')],
                ['Dev Mode', $client->is_dev_mode ? 'yes' : 'no'],
                ['Allowed Features', $featuresDisplay],
                ['Monthly Cap', $capUsd],
                ['Month Spend', $spentUsd],
                ['Remaining', $remaining],
                ['Requests (this month)', (string) ($monthlyStats !== null ? $monthlyStats->total_requests : 0)],
                ['Failed Requests', (string) ($monthlyStats !== null ? $monthlyStats->failed_requests : 0)],
                ['Avg Latency (s)', $monthlyStats?->avg_latency_seconds !== null ? number_format((float) $monthlyStats->avg_latency_seconds, 2) : 'n/a'],
                ['Top Models', $topModels->isEmpty() ? 'none' : $topModels->map(fn ($count, $alias) => "{$alias} ({$count})")->implode(', ')],
            ],
        );

        return self::SUCCESS;
    }
}
