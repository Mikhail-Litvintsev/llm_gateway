<?php

declare(strict_types=1);

namespace App\Components\Caching;

use App\Components\Caching\DTO\CacheInjectionResult;
use App\Models\Client;

readonly class Caching
{
    public function __construct(
        private AutoCacheInjector $injector,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function autoInject(array $payload, string $modelAlias, Client $client): CacheInjectionResult
    {
        return $this->injector->inject($payload, $modelAlias, $client);
    }
}
