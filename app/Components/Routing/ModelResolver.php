<?php

declare(strict_types=1);

namespace App\Components\Routing;

use App\Components\Routing\DTO\ModelCapabilities;
use App\Components\Routing\DTO\ResolvedModel;
use App\Components\Routing\Exceptions\UnknownModelAliasException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class ModelResolver
{
    private const int CAPABILITIES_TTL = 3600;

    public function __construct(
        private readonly ?ModelCapabilitiesFetcher $fetcher = null,
    ) {}

    public function resolve(string $alias): ResolvedModel
    {
        $version = config('llm.version');
        $cacheKey = "routing:model:v{$version}:{$alias}";

        $data = Cache::remember($cacheKey, 3600, function () use ($alias): array {
            $aliases = config('llm.claude.model_aliases');

            if (! isset($aliases[$alias])) {
                throw new UnknownModelAliasException($alias);
            }

            return [
                'alias' => $alias,
                'snapshot' => $aliases[$alias],
                'capabilities' => config("llm.claude.model_capabilities.{$alias}", []),
                'pricing' => config("llm.claude.pricing.{$alias}", []),
            ];
        });

        return new ResolvedModel(
            alias: $data['alias'],
            snapshot: $data['snapshot'],
            capabilities: $data['capabilities'],
            pricing: $data['pricing'],
        );
    }

    public function getCapabilities(string $alias, bool $live = false): ModelCapabilities
    {
        $resolved = $this->resolve($alias);
        $snapshot = $resolved->snapshot;
        $configCaps = ModelCapabilities::fromConfig($snapshot, $resolved->capabilities);

        if (! $live || $this->fetcher === null) {
            return $configCaps;
        }

        $redisKey = "claude:caps:$snapshot";
        $cached = Redis::get($redisKey);

        if ($cached !== null) {
            $data = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);

            return ModelCapabilities::fromApi($data);
        }

        $liveCaps = $this->fetcher->fetchSafe($snapshot, $configCaps);

        if ($liveCaps->fetchedAt !== null) {
            Redis::setex($redisKey, self::CAPABILITIES_TTL, json_encode($liveCaps->toArray(), JSON_THROW_ON_ERROR));
        }

        return $liveCaps;
    }
}
