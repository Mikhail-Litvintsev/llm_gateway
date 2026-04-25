<?php

declare(strict_types=1);

namespace App\Components\Billing;

use App\Components\Billing\DTO\TokenEstimate;

final class CostEstimator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function estimate(array $payload, string $modelAlias): float
    {
        $tokens = $this->estimateTokens($payload, $modelAlias);

        return $this->estimateFromTokens($tokens->inputTokens, $tokens->outputTokens, $modelAlias);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function estimateTokens(array $payload, string $modelAlias): TokenEstimate
    {
        $chars = $this->countCharsInPayload($payload);
        $charsPerToken = (float) config('llm.claude.caching.estimation_chars_per_token', 3.5);
        $inputTokens = (int) ceil($chars / $charsPerToken);
        $outputFactor = (float) config('llm.claude.count_tokens.output_tokens_factor', 0.5);
        $outputTokens = (int) ceil(($payload['max_tokens'] ?? 1024) * $outputFactor);

        return new TokenEstimate(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheReadTokens: 0,
        );
    }

    public function estimateFromTokens(int $inputTokens, int $outputTokens, string $modelAlias): float
    {
        $pricing = config("llm.claude.pricing.$modelAlias", []);
        $inputPrice = (float) ($pricing['input'] ?? 0.0);
        $outputPrice = (float) ($pricing['output'] ?? 0.0);

        return ($inputTokens * $inputPrice / 1_000_000) + ($outputTokens * $outputPrice / 1_000_000);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function countCharsInPayload(array $payload): int
    {
        $chars = 0;

        foreach ($payload['messages'] ?? [] as $message) {
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $chars += mb_strlen($content);

                continue;
            }

            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block)) {
                        $chars += mb_strlen($block['text'] ?? '');
                    }
                }
            }
        }

        foreach ($payload['system'] ?? [] as $block) {
            if (is_string($block)) {
                $chars += mb_strlen($block);
            } elseif (is_array($block)) {
                $chars += mb_strlen($block['text'] ?? '');
            }
        }

        return $chars;
    }
}
