<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class ClaudeTokenBudget extends Command
{
    protected $signature = 'llm:claude-token-budget';

    protected $description = 'Show current Claude token budget status (remaining tokens and reset times)';

    public function handle(): int
    {
        $inputRemaining = Cache::get('token_budget:claude:input_remaining');
        $outputRemaining = Cache::get('token_budget:claude:output_remaining');
        $inputReset = Cache::get('token_budget:claude:input_reset');
        $outputReset = Cache::get('token_budget:claude:output_reset');

        if ($inputRemaining === null && $outputRemaining === null) {
            $this->warn('No token budget data in cache (no requests sent yet or data expired).');
            $this->newLine();
            $this->showPauseStatus();
            $this->newLine();
            $this->showQueueStats();
            return self::SUCCESS;
        }

        $inputLimit = (int) config('llm.providers.claude.token_limits.input_tokens_per_minute', 30000);
        $outputLimit = (int) config('llm.providers.claude.token_limits.output_tokens_per_minute', 8000);

        $rows = [];

        if ($inputRemaining !== null) {
            $inputUsedPct = $inputLimit > 0 ? round((1 - (int) $inputRemaining / $inputLimit) * 100, 1) : 0;
            $rows[] = [
                'Input tokens per minute',
                number_format($inputLimit),
                number_format((int) $inputRemaining),
                $inputUsedPct . '%',
                $this->formatReset($inputReset),
            ];
        }

        if ($outputRemaining !== null) {
            $outputUsedPct = $outputLimit > 0 ? round((1 - (int) $outputRemaining / $outputLimit) * 100, 1) : 0;
            $rows[] = [
                'Output tokens per minute',
                number_format($outputLimit),
                number_format((int) $outputRemaining),
                $outputUsedPct . '%',
                $this->formatReset($outputReset),
            ];
        }

        $this->table(
            ['Metric', 'Limit', 'Remaining', 'Used %', 'Resets At'],
            $rows,
        );

        $this->newLine();
        $this->showPauseStatus();

        $this->newLine();
        $this->showQueueStats();

        return self::SUCCESS;
    }

    private function formatReset(?string $resetTimestamp): string
    {
        if (!$resetTimestamp) {
            return '—';
        }

        $unix = strtotime($resetTimestamp);
        if ($unix === false) {
            return $resetTimestamp;
        }

        return date('Y-m-d H:i:s', $unix);
    }

    private function showQueueStats(): void
    {
        $total = 0;

        foreach (config('llm.queues') as $queueName) {
            $total += Queue::size($queueName);
        }

        $this->line("Queue: {$total}");
    }

    private function showPauseStatus(): void
    {
        $pauseInfo = Cache::get('provider_paused:claude');

        if ($pauseInfo === null) {
            $this->info('Provider status: active');
            return;
        }

        $this->error('Provider status: PAUSED');
        $this->line('  Reason: ' . ($pauseInfo['reason'] ?? 'unknown'));
        $this->line('  Paused at: ' . ($pauseInfo['paused_at'] ?? '—'));

        if (isset($pauseInfo['auto_resume_at'])) {
            $this->line('  Auto-resume at: ' . ($pauseInfo['auto_resume_at']));
        } else {
            $this->line('  Resume: php artisan llm:resume-provider claude');
        }
    }
}
