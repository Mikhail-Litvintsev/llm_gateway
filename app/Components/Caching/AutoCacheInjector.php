<?php

declare(strict_types=1);

namespace App\Components\Caching;

use App\Components\Caching\DTO\CacheInjectionResult;
use App\Components\Caching\Enums\CacheInjectionOutcome;
use App\Models\Client;

class AutoCacheInjector
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function inject(array $payload, string $modelAlias, Client $client): CacheInjectionResult
    {
        $allowedFeatures = $client->allowed_features ?? [];

        if (($allowedFeatures['auto_cache_injection'] ?? false) !== true) {
            return new CacheInjectionResult(
                payload: $payload,
                outcome: CacheInjectionOutcome::SkippedDisabled,
                reason: 'auto_cache_injection is not enabled for this client',
            );
        }

        if ($this->hasCacheControl($payload)) {
            return new CacheInjectionResult(
                payload: $payload,
                outcome: CacheInjectionOutcome::SkippedAlreadyPresent,
                reason: 'payload already contains cache_control markers',
            );
        }

        $charsPerToken = (float) config('llm.claude.caching.estimation_chars_per_token', 3.5);
        $prefixChars = $this->countPrefixChars($payload);
        $estimatedTokens = (int) ceil($prefixChars / $charsPerToken);

        $modelFamily = $this->resolveModelFamily($modelAlias);
        $minimumTokens = $this->getMinimumPrefixTokens($modelFamily);

        if ($estimatedTokens < $minimumTokens) {
            return new CacheInjectionResult(
                payload: $payload,
                outcome: CacheInjectionOutcome::SkippedPrefixTooShort,
                estimatedPrefixTokens: $estimatedTokens,
                reason: "estimated prefix tokens ($estimatedTokens) below minimum ($minimumTokens) for $modelFamily",
            );
        }

        $maxBreakpoints = (int) ($allowedFeatures['auto_cache_injection_max_breakpoints'] ?? 4);
        $existingBreakpoints = $this->countCacheControlMarkers($payload);

        if ($existingBreakpoints >= $maxBreakpoints) {
            return new CacheInjectionResult(
                payload: $payload,
                outcome: CacheInjectionOutcome::SkippedCapExceeded,
                estimatedPrefixTokens: $estimatedTokens,
                reason: "existing cache_control markers ($existingBreakpoints) reached cap ($maxBreakpoints)",
            );
        }

        $injectedPayload = ['cache_control' => ['type' => 'ephemeral'], ...$payload];

        return new CacheInjectionResult(
            payload: $injectedPayload,
            outcome: CacheInjectionOutcome::Injected,
            estimatedPrefixTokens: $estimatedTokens,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasCacheControl(array $payload): bool
    {
        if (array_key_exists('cache_control', $payload)) {
            return true;
        }

        $system = $payload['system'] ?? [];
        if (is_array($system) && array_any($system, fn (mixed $block): bool => is_array($block) && array_key_exists('cache_control', $block))) {
            return true;
        }

        foreach ($payload['messages'] ?? [] as $message) {
            $content = $message['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }
            if (array_any($content, fn (mixed $block): bool => is_array($block) && array_key_exists('cache_control', $block))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function countPrefixChars(array $payload): int
    {
        $chars = 0;

        $chars += $this->countCharsInSystem($payload['system'] ?? []);
        $chars += $this->countCharsInTools($payload['tools'] ?? []);
        $chars += $this->countCharsInMessagesExceptLast($payload['messages'] ?? []);

        return $chars;
    }

    private function countCharsInSystem(mixed $system): int
    {
        if (is_string($system)) {
            return mb_strlen($system);
        }

        if (! is_array($system)) {
            return 0;
        }

        $chars = 0;
        foreach ($system as $block) {
            if (is_string($block)) {
                $chars += mb_strlen($block);
            } elseif (is_array($block)) {
                $chars += mb_strlen((string) ($block['text'] ?? ''));
            }
        }

        return $chars;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function countCharsInTools(array $tools): int
    {
        if ($tools === []) {
            return 0;
        }

        return mb_strlen((string) json_encode($tools));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function countCharsInMessagesExceptLast(array $messages): int
    {
        if (count($messages) <= 1) {
            return 0;
        }

        $chars = 0;
        $prefixMessages = array_slice($messages, 0, -1);

        foreach ($prefixMessages as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $chars += mb_strlen($content);
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (is_string($block)) {
                        $chars += mb_strlen($block);
                    } elseif (is_array($block)) {
                        $chars += mb_strlen((string) ($block['text'] ?? ''));
                    }
                }
            }
        }

        return $chars;
    }

    private function resolveModelFamily(string $modelAlias): string
    {
        if (str_contains($modelAlias, 'opus')) {
            return 'opus';
        }

        if (str_contains($modelAlias, 'haiku')) {
            return 'haiku';
        }

        return 'sonnet';
    }

    private function getMinimumPrefixTokens(string $modelFamily): int
    {
        $minimums = config('llm.claude.caching.minimum_prefix_tokens', [
            'opus' => 1024,
            'sonnet' => 1024,
            'haiku' => 2048,
        ]);

        return (int) ($minimums[$modelFamily] ?? 1024);
    }

    /**
     * @param  array<string, mixed>  $itemPayload
     * @return array<string, mixed>
     */
    public function injectForBatchItem(array $itemPayload, string $resolvedModel, bool $autoUse1hCache): array
    {
        if ($this->hasCacheControl($itemPayload)) {
            return $itemPayload;
        }

        $charsPerToken = (float) config('llm.claude.caching.estimation_chars_per_token', 3.5);
        $prefixChars = $this->countPrefixChars($itemPayload);
        $estimatedTokens = (int) ceil($prefixChars / $charsPerToken);

        $modelFamily = $this->resolveModelFamily($resolvedModel);
        $minimumTokens = $this->getMinimumPrefixTokens($modelFamily);

        if ($estimatedTokens < $minimumTokens) {
            return $itemPayload;
        }

        $ttl = $autoUse1hCache ? '1h' : '5m';

        return $this->applyCacheControlToSystem($itemPayload, $ttl);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyCacheControlToSystem(array $payload, string $ttl): array
    {
        $system = $payload['system'] ?? null;

        if ($system === null) {
            return $payload;
        }

        if (is_string($system)) {
            $payload['system'] = [
                [
                    'type' => 'text',
                    'text' => $system,
                    'cache_control' => ['type' => 'ephemeral', 'ttl' => $ttl],
                ],
            ];

            return $payload;
        }

        if (is_array($system) && $system !== []) {
            $lastIndex = array_key_last($system);
            $system[$lastIndex]['cache_control'] = ['type' => 'ephemeral', 'ttl' => $ttl];
            $payload['system'] = $system;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function countCacheControlMarkers(array $payload): int
    {
        $count = 0;

        $system = $payload['system'] ?? [];
        if (is_array($system)) {
            foreach ($system as $block) {
                if (is_array($block) && array_key_exists('cache_control', $block)) {
                    $count++;
                }
            }
        }

        foreach ($payload['messages'] ?? [] as $message) {
            $content = $message['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (is_array($block) && array_key_exists('cache_control', $block)) {
                    $count++;
                }
            }
        }

        foreach ($payload['tools'] ?? [] as $tool) {
            if (is_array($tool) && array_key_exists('cache_control', $tool)) {
                $count++;
            }
        }

        return $count;
    }
}
