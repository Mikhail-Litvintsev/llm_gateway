<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RequestsCleanup extends Command
{
    protected $signature = 'requests:cleanup';

    protected $description = 'TTL-based cleanup of expired requests, raw data, and async pending records';

    public function handle(): int
    {
        $rawDays = (int) config('llm.raw_log_retention_days', 14);
        $requestsDays = (int) config('llm.session_default_ttl_days', 30);

        $asyncCutoff = now()->subDay();
        $asyncDeleted = DB::table('async_pending')
            ->where('expires_at', '<', $asyncCutoff)
            ->delete();

        $this->info("async_pending: {$asyncDeleted} rows deleted");

        $rawCutoff = now()->subDays($rawDays);
        $rawDeleted = DB::table('request_raw')
            ->whereIn('request_id', function (Builder $query) use ($rawCutoff): void {
                $query->select('request_id')
                    ->from('requests')
                    ->where('created_at', '<', $rawCutoff);
            })
            ->delete();

        $this->info("request_raw: {$rawDeleted} rows deleted");

        $requestsCutoff = now()->subDays($requestsDays);
        $usageDeleted = DB::table('request_usage')
            ->whereIn('request_id', function (Builder $query) use ($requestsCutoff): void {
                $query->select('request_id')
                    ->from('requests')
                    ->where('created_at', '<', $requestsCutoff);
            })
            ->delete();

        $this->info("request_usage: {$usageDeleted} rows deleted");

        $requestsDeleted = DB::table('requests')
            ->where('created_at', '<', $requestsCutoff)
            ->delete();

        $this->info("requests: {$requestsDeleted} rows deleted");

        return self::SUCCESS;
    }
}
