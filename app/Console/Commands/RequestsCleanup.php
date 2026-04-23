<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RequestsCleanup extends Command
{
    protected $signature = 'requests:cleanup';

    protected $description = 'TTL-based cleanup of expired requests, raw data, and async pending records';

    /**
     * Delete expired records from async_pending, request_raw, request_usage, and requests tables.
     */
    public function handle(): int
    {
        $rawDays = (int) config('llm.raw_log_retention_days', 14);
        $requestsDays = (int) config('llm.session_default_ttl_days', 30);

        $asyncDeleted = DB::table('async_pending')
            ->where('expires_at', '<', DB::raw('NOW() - INTERVAL 1 DAY'))
            ->delete();

        $this->info("async_pending: {$asyncDeleted} rows deleted");

        $rawDeleted = DB::table('request_raw')
            ->whereIn('request_id', function ($query) use ($rawDays): void {
                $query->select('request_id')
                    ->from('requests')
                    ->where('created_at', '<', DB::raw("NOW() - INTERVAL {$rawDays} DAY"));
            })
            ->delete();

        $this->info("request_raw: {$rawDeleted} rows deleted");

        $usageDeleted = DB::table('request_usage')
            ->whereIn('request_id', function ($query) use ($requestsDays): void {
                $query->select('request_id')
                    ->from('requests')
                    ->where('created_at', '<', DB::raw("NOW() - INTERVAL {$requestsDays} DAY"));
            })
            ->delete();

        $this->info("request_usage: {$usageDeleted} rows deleted");

        $requestsDeleted = DB::table('requests')
            ->where('created_at', '<', DB::raw("NOW() - INTERVAL {$requestsDays} DAY"))
            ->delete();

        $this->info("requests: {$requestsDeleted} rows deleted");

        return self::SUCCESS;
    }
}
