<?php

namespace App\Console\Commands;

use App\Components\RateLimiter\RequestThrottle;
use Illuminate\Console\Command;

class ResumeProvider extends Command
{
    protected $signature = 'llm:resume-provider
        {provider : Provider name (claude, openai, deepseek, gemini, mistral) or "all"}';

    protected $description = 'Resume a paused provider (removes pause flag, queued jobs will be processed)';

    public function handle(RequestThrottle $throttle): int
    {
        $provider = $this->argument('provider');

        if ($provider === 'all') {
            return $this->resumeAll($throttle);
        }

        $providers = array_keys(config('llm.providers', []));
        if (!in_array($provider, $providers)) {
            $this->error("Unknown provider: {$provider}. Available: " . implode(', ', $providers));
            return self::FAILURE;
        }

        if (!$throttle->isProviderPaused($provider)) {
            $this->info("Provider '{$provider}' is not paused.");
            return self::SUCCESS;
        }

        $info = $throttle->getProviderPauseInfo($provider);
        $throttle->resumeProvider($provider);

        $this->info("Provider '{$provider}' resumed.");
        $this->line("  Was paused since: {$info['paused_at']}");
        $this->line("  Reason: {$info['reason']}");

        return self::SUCCESS;
    }

    private function resumeAll(RequestThrottle $throttle): int
    {
        $paused = $throttle->getAllPausedProviders();

        if (empty($paused)) {
            $this->info('No providers are currently paused.');
            return self::SUCCESS;
        }

        foreach ($paused as $name => $info) {
            $throttle->resumeProvider($name);
            $this->info("Provider '{$name}' resumed (was paused: {$info['reason']} since {$info['paused_at']}).");
        }

        return self::SUCCESS;
    }
}
