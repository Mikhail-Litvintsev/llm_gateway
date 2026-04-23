<?php

namespace App\Console\Commands;

use App\Components\RateLimiter\RequestThrottle;
use Illuminate\Console\Command;

class ProviderStatus extends Command
{
    protected $signature = 'llm:provider-status';

    protected $description = 'Show status of all providers (active/paused)';

    public function handle(RequestThrottle $throttle): int
    {
        $providers = array_keys(config('llm.providers', []));
        $rows = [];

        foreach ($providers as $name) {
            $info = $throttle->getProviderPauseInfo($name);
            $rateLimit = config("llm.providers.{$name}.rate_limit", '—');

            if ($info) {
                $rows[] = [
                    $name,
                    '<fg=red>PAUSED</>',
                    $info['reason'],
                    $info['paused_at'],
                    $rateLimit . ' RPM',
                ];
            } else {
                $rows[] = [
                    $name,
                    '<fg=green>ACTIVE</>',
                    '—',
                    '—',
                    $rateLimit . ' RPM',
                ];
            }
        }

        $this->table(
            ['Provider', 'Status', 'Pause Reason', 'Paused At', 'Rate Limit'],
            $rows,
        );

        return self::SUCCESS;
    }
}
