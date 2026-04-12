<?php

declare(strict_types=1);

namespace App\Components\Claude\Beta;

final class BetaHeaderRegistry
{
    /** @param array<string, string> $registry */
    public function __construct(
        private readonly array $registry,
    ) {}

    /** @param string[] $features */
    public function assemble(array $features): string
    {
        $values = [];

        foreach ($features as $feature) {
            if (! isset($this->registry[$feature])) {
                throw new \InvalidArgumentException("Unknown beta feature: {$feature}");
            }
            $values[] = $this->registry[$feature];
        }

        return implode(',', $values);
    }
}
