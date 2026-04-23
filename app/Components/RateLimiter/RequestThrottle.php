<?php

namespace App\Components\RateLimiter;

use App\Components\RateLimiter\DTO\ThrottleResult;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class RequestThrottle
{
    private const PAUSE_KEY_PREFIX = 'provider_paused:';

    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Проверяет и потребляет лимит для клиента.
     * Использует sliding window через Redis.
     */
    public function attempt(int $clientId, int $maxAttempts): ThrottleResult
    {
        return $this->doAttempt("rate_limit:client:{$clientId}", $maxAttempts);
    }

    /**
     * Проверяет и потребляет лимит для провайдера (RPM).
     */
    public function attemptProvider(string $providerName): ThrottleResult
    {
        $maxAttempts = (int) config("llm.providers.{$providerName}.rate_limit", 60);

        return $this->doAttempt("rate_limit:provider:{$providerName}", $maxAttempts);
    }

    /**
     * Ставит провайдера на паузу до ручного возобновления.
     */
    public function pauseProvider(string $providerName, string $reason): void
    {
        Cache::forever(self::PAUSE_KEY_PREFIX . $providerName, [
            'reason' => $reason,
            'paused_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Снимает паузу с провайдера.
     */
    public function resumeProvider(string $providerName): bool
    {
        return Cache::forget(self::PAUSE_KEY_PREFIX . $providerName);
    }

    /**
     * Проверяет, стоит ли провайдер на паузе.
     */
    public function isProviderPaused(string $providerName): bool
    {
        return Cache::has(self::PAUSE_KEY_PREFIX . $providerName);
    }

    /**
     * Возвращает информацию о паузе провайдера или null.
     */
    public function getProviderPauseInfo(string $providerName): ?array
    {
        return Cache::get(self::PAUSE_KEY_PREFIX . $providerName);
    }

    /**
     * Возвращает список всех поставленных на паузу провайдеров.
     */
    public function getAllPausedProviders(): array
    {
        $paused = [];
        $providers = array_keys(config('llm.providers', []));

        foreach ($providers as $name) {
            $info = $this->getProviderPauseInfo($name);
            if ($info !== null) {
                $paused[$name] = $info;
            }
        }

        return $paused;
    }

    private function doAttempt(string $key, int $maxAttempts): ThrottleResult
    {
        $decaySeconds = 60; // окно — 1 минута

        $executed = $this->limiter->attempt(
            $key,
            $maxAttempts,
            fn () => true,
            $decaySeconds,
        );

        if ($executed) {
            $remaining = $this->limiter->remaining($key, $maxAttempts);

            return new ThrottleResult(
                allowed: true,
                limit: $maxAttempts,
                remaining: max(0, $remaining),
                resetTimestamp: time() + ($this->limiter->availableIn($key) ?: $decaySeconds),
                retryAfter: null,
            );
        }

        $retryAfter = $this->limiter->availableIn($key);

        return new ThrottleResult(
            allowed: false,
            limit: $maxAttempts,
            remaining: 0,
            resetTimestamp: time() + $retryAfter,
            retryAfter: $retryAfter,
        );
    }
}
