<?php

namespace App\Components\RequestPipeline;

use Illuminate\Support\Facades\Cache;

class IdempotencyGuard
{
    private const TTL_HOURS = 24;

    /**
     * Проверяет, существует ли уже ответ для данного idempotency key.
     *
     * @return array|null — кешированный ответ или null
     */
    public function check(string $idempotencyKey, int $clientId): ?array
    {
        $cacheKey = "idempotency:{$clientId}:{$idempotencyKey}";

        return Cache::get($cacheKey);
    }

    /**
     * Сохраняет ответ для idempotency key.
     */
    public function store(string $idempotencyKey, int $clientId, array $response): void
    {
        $cacheKey = "idempotency:{$clientId}:{$idempotencyKey}";

        Cache::put($cacheKey, $response, now()->addHours(self::TTL_HOURS));
    }
}
