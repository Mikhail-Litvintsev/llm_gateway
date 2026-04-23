<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Components\Logging\CapabilityDriftLogger;
use App\Components\Routing\DTO\ModelCapabilities;
use App\Components\Routing\ModelCapabilitiesFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class SyncClaudeCapabilities extends Command
{
    protected $signature = 'claude:sync-capabilities';

    protected $description = 'Fetch live model capabilities from Anthropic and detect drift from config';

    private const int CAPABILITIES_TTL = 3600;

    public function __construct(
        private readonly ModelCapabilitiesFetcher $fetcher,
        private readonly CapabilityDriftLogger $driftLogger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $aliases = config('llm.claude.model_aliases', []);
        $capabilities = config('llm.claude.model_capabilities', []);
        $totalCount = count($aliases);
        $errorCount = 0;

        foreach ($aliases as $alias => $snapshot) {
            $configCaps = ModelCapabilities::fromConfig($snapshot, $capabilities[$alias] ?? []);

            try {
                $liveCaps = $this->fetcher->fetch($snapshot);
            } catch (Throwable $e) {
                $this->error("Failed to fetch $snapshot: {$e->getMessage()}");
                $errorCount++;

                continue;
            }

            $redisKey = "claude:caps:$snapshot";
            Redis::setex($redisKey, self::CAPABILITIES_TTL, json_encode($liveCaps->toArray(), JSON_THROW_ON_ERROR));

            $this->driftLogger->log($snapshot, $configCaps, $liveCaps);

            $drift = $configCaps->diff($liveCaps);
            if ($drift !== []) {
                $this->warn("Drift detected for $snapshot: ".json_encode($drift));
            } else {
                $this->info("$snapshot: OK");
            }
        }

        if ($totalCount > 0 && $errorCount >= (int) ceil($totalCount * 0.5)) {
            $this->error("Hard failure: $errorCount/$totalCount snapshots failed");

            return 1;
        }

        return 0;
    }
}
