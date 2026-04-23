<?php

declare(strict_types=1);

namespace App\Components\Logging;

use App\Components\Routing\DTO\ModelCapabilities;
use Psr\Log\LoggerInterface;

final readonly class CapabilityDriftLogger
{
    public function __construct(
        private LoggerInterface $log,
    ) {}

    public function log(string $snapshot, ModelCapabilities $config, ModelCapabilities $live): void
    {
        $drift = $config->diff($live);

        if ($drift === []) {
            return;
        }

        $this->log->warning('Model capability drift detected', [
            'snapshot' => $snapshot,
            'drift' => $drift,
        ]);
    }
}
