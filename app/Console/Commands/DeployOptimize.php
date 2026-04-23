<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployOptimize extends Command
{
    protected $signature = 'llm:optimize';

    protected $description = 'Run production optimization commands';

    public function handle(): int
    {
        $commands = [
            'config:cache',
            'route:cache',
            'event:cache',
            'view:cache',
        ];

        foreach ($commands as $command) {
            $this->info("Running {$command}...");
            $this->call($command);
        }

        $this->info('Production optimization complete.');

        return self::SUCCESS;
    }
}
