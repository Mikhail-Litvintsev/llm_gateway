<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Components\Healthcheck\Enums\HealthStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

final class ClaudeStatus extends Command
{
    protected $signature = 'claude:status';

    protected $description = 'Show Claude API health status and rate limit snapshot';

    /**
     * Read Redis healthcheck cache and rate limit data, display with colored output.
     */
    public function handle(): int
    {
        $cached = Redis::connection('cache')->get('claude:healthcheck:anthropic');

        if (! $cached) {
            $this->warn('No healthcheck data available (no recent ping).');

            return self::SUCCESS;
        }

        $data = json_decode($cached, true);
        $status = HealthStatus::from($data['status']);

        $statusLine = match ($status) {
            HealthStatus::Ok => '<fg=green>OK</>',
            HealthStatus::Degraded => '<fg=yellow>DEGRADED</>',
            HealthStatus::Down => '<fg=red>DOWN</>',
        };

        $this->info("Anthropic API Status: {$statusLine}");

        if (isset($data['latency_ms'])) {
            $this->info("  Latency: {$data['latency_ms']}ms");
        }

        if (isset($data['error'])) {
            $this->error("  Error: {$data['error']}");
        }

        if (isset($data['checked_at'])) {
            $this->info("  Last check: {$data['checked_at']}");
        }

        $this->newLine();
        $this->displayRateLimitSnapshot();

        $paused = Redis::connection('cache')->get('claude:pause:global');
        if ($paused) {
            $this->warn('Global pause is ACTIVE. Run `claude:resume` to clear.');
        }

        if ($status === HealthStatus::Down) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function displayRateLimitSnapshot(): void
    {
        $keys = Redis::connection('cache')->keys('claude_rl:*');

        if (empty($keys)) {
            $this->info('No rate limit data cached.');

            return;
        }

        $this->info('Rate Limit Snapshots:');

        $rows = [];
        foreach ($keys as $key) {
            $raw = Redis::connection('cache')->get($key);
            if (! $raw) {
                continue;
            }

            $snapshot = json_decode($raw, true);
            $keyParts = explode(':', str_replace('laravel_cache:', '', $key));
            $model = end($keyParts);

            $rows[] = [
                $model,
                "{$snapshot['input_tokens_remaining']}/{$snapshot['input_tokens_limit']}",
                "{$snapshot['output_tokens_remaining']}/{$snapshot['output_tokens_limit']}",
                "{$snapshot['requests_remaining']}/{$snapshot['requests_limit']}",
                $snapshot['recorded_at'],
            ];
        }

        $this->table(
            ['Model', 'Input Tokens (rem/lim)', 'Output Tokens (rem/lim)', 'Requests (rem/lim)', 'Recorded At'],
            $rows,
        );
    }
}
