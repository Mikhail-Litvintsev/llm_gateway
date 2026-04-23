<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

final class ClaudeResume extends Command
{
    protected $signature = 'claude:resume';

    protected $description = 'Clear the global pause flag for Claude API requests';

    /**
     * Delete the Redis pause key so requests resume flowing to the Claude API.
     */
    public function handle(): int
    {
        $deleted = Redis::connection('cache')->del('claude:pause:global');

        if ($deleted) {
            $this->info('Global pause cleared. Requests will resume.');
        } else {
            $this->info('No pause was active.');
        }

        $this->info('Next healthcheck ping will run within 1 minute.');

        return self::SUCCESS;
    }
}
