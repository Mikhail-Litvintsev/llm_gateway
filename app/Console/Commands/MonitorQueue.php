<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class MonitorQueue extends Command
{
    protected $signature = 'queue:monitor';

    protected $description = 'Show queue depths, failed jobs counts, and stuck async requests.';

    public function handle(): int
    {
        $this->showQueueDepths();
        $this->newLine();
        $this->showFailedJobs();
        $this->newLine();
        $this->showStuckAsyncRequests();

        return self::SUCCESS;
    }

    private function showQueueDepths(): void
    {
        $this->info('<fg=cyan>Queue depths</>');

        $queues = ['high', 'default', 'low', 'batch'];
        $connection = Redis::connection(config('queue.connections.redis.connection', 'default'));

        foreach ($queues as $queue) {
            $length = (int) $connection->llen("queues:{$queue}");
            $delayed = (int) $connection->zcard("queues:{$queue}:delayed");
            $reserved = (int) $connection->zcard("queues:{$queue}:reserved");
            $total = $length + $delayed + $reserved;

            $color = $total > 1000 ? 'red' : ($total > 100 ? 'yellow' : 'green');
            $this->line(sprintf(
                '  %-10s  pending=%-5d  delayed=%-5d  reserved=%-5d  <fg=%s>total=%d</>',
                $queue,
                $length,
                $delayed,
                $reserved,
                $color,
                $total,
            ));
        }
    }

    private function showFailedJobs(): void
    {
        $this->info('<fg=cyan>Failed jobs</>');

        $count = DB::table('failed_jobs')->count();
        $this->line("  total: {$count}");

        if ($count === 0) {
            return;
        }

        $latest = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(1)
            ->first();

        if ($latest) {
            $this->line("  latest: {$latest->failed_at} — {$latest->queue}");
            $class = $this->extractJobClass($latest->payload ?? '');
            if ($class !== null) {
                $this->line("  class:  {$class}");
            }
        }

        $this->warn('  Use `php artisan queue:failed` for details, `queue:retry {id}` to retry.');
    }

    private function showStuckAsyncRequests(): void
    {
        $this->info('<fg=cyan>Stuck async requests</>');

        $threshold = now()->subMinutes(5);
        $stuck = DB::table('async_pending')
            ->where('status', 'processing')
            ->where(function ($q) use ($threshold): void {
                $q->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<', $threshold);
            })
            ->where('updated_at', '<', $threshold)
            ->count();

        if ($stuck === 0) {
            $this->line('  <fg=green>none</>');

            return;
        }

        $this->warn("  {$stuck} async_pending rows in 'processing' older than 5 min.");
        $this->line('  Inspect via: SELECT request_id, callback_attempts, next_attempt_at, updated_at FROM async_pending WHERE status=\'processing\' AND updated_at < NOW() - INTERVAL 5 MINUTE;');
    }

    private function extractJobClass(string $payload): ?string
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        $commandName = $decoded['data']['commandName'] ?? null;

        return is_string($commandName) ? $commandName : null;
    }
}
