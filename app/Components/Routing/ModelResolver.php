<?php

declare(strict_types=1);

namespace App\Components\Routing;

use App\Components\Routing\DTO\ResolvedModel;
use App\Components\Routing\Exceptions\UnknownModelAliasException;
use Illuminate\Support\Facades\Cache;

final class ModelResolver
{
    public function resolve(string $alias): ResolvedModel
    {
        $version = config('llm.version');
        $cacheKey = "routing:model:v{$version}:{$alias}";

        return Cache::remember($cacheKey, 3600, function () use ($alias): ResolvedModel {
            $aliases = config('llm.claude.model_aliases');

            if (!isset($aliases[$alias])) {
                throw new UnknownModelAliasException($alias);
            }

            return new ResolvedModel(
                alias: $alias,
                snapshot: $aliases[$alias],
                capabilities: config("llm.claude.model_capabilities.{$alias}", []),
                pricing: config("llm.claude.pricing.{$alias}", []),
            );
        });
    }
}
