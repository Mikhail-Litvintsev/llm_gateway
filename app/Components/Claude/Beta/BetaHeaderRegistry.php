<?php

declare(strict_types=1);

namespace App\Components\Claude\Beta;

use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\Claude\ToolTypeCatalog;
use InvalidArgumentException;

final readonly class BetaHeaderRegistry
{
    /** @param array<string, string> $registry */
    public function __construct(
        private array $registry,
    ) {}

    /** @param string[] $features */
    public function assemble(array $features): string
    {
        $values = [];

        foreach ($features as $feature) {
            if (! isset($this->registry[$feature])) {
                throw new InvalidArgumentException("Unknown beta feature: $feature");
            }
            $values[] = $this->registry[$feature];
        }

        return implode(',', $values);
    }

    public function assembleFromPayload(BuiltPayload $payload): string
    {
        $betas = $payload->betaHeaders;

        if (in_array(ToolTypeCatalog::MEMORY, $payload->serverToolTypes, true)) {
            $betas[] = ToolTypeCatalog::BETA_CONTEXT_MANAGEMENT;
        }

        if (in_array(ToolTypeCatalog::COMPUTER, $payload->serverToolTypes, true)) {
            $betas[] = ToolTypeCatalog::BETA_COMPUTER_USE;
        }

        return implode(',', array_values(array_unique($betas)));
    }
}
