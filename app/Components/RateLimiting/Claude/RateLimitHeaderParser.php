<?php

declare(strict_types=1);

namespace App\Components\RateLimiting\Claude;

final class RateLimitHeaderParser
{
    private const array HEADER_MAP = [
        'messages' => [
            'rpm_remaining' => 'anthropic-ratelimit-requests-remaining',
            'rpm_limit' => 'anthropic-ratelimit-requests-limit',
            'rpm_reset' => 'anthropic-ratelimit-requests-reset',
            'itpm_remaining' => 'anthropic-ratelimit-input-tokens-remaining',
            'itpm_limit' => 'anthropic-ratelimit-input-tokens-limit',
            'itpm_reset' => 'anthropic-ratelimit-input-tokens-reset',
            'otpm_remaining' => 'anthropic-ratelimit-output-tokens-remaining',
            'otpm_limit' => 'anthropic-ratelimit-output-tokens-limit',
            'otpm_reset' => 'anthropic-ratelimit-output-tokens-reset',
            'tokens_remaining' => 'anthropic-ratelimit-tokens-remaining',
            'tokens_limit' => 'anthropic-ratelimit-tokens-limit',
            'tokens_reset' => 'anthropic-ratelimit-tokens-reset',
        ],
        'batch_create' => [
            'rpm_remaining' => 'anthropic-ratelimit-batches-remaining',
            'rpm_limit' => 'anthropic-ratelimit-batches-limit',
            'rpm_reset' => 'anthropic-ratelimit-batches-reset',
            'queue_remaining' => 'anthropic-ratelimit-batches-queue-remaining',
            'queue_reset' => 'anthropic-ratelimit-batches-queue-reset',
        ],
    ];

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string|int|null>
     */
    public function parse(array $headers, RateLimitNamespace $ns): array
    {
        $normalized = $this->normalizeHeaders($headers);
        $map = self::HEADER_MAP[$ns->value] ?? [];
        $result = [];

        foreach ($map as $key => $headerName) {
            $result[$key] = $normalized[$headerName] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value) ? $value[0] : (string) $value;
        }

        return $normalized;
    }
}
