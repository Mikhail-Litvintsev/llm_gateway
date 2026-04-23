<?php

declare(strict_types=1);

namespace App\Components\Billing;

final class CostEstimator
{
    public function estimate(array $payload, string $modelAlias): float
    {
        $chars = $this->countCharsInPayload($payload);
        $charsPerToken = (float) config('llm.claude.caching.estimation_chars_per_token', 3.5);
        $inputTokens = (int) ceil($chars / $charsPerToken);
        $outputTokens = (int) ceil(($payload['max_tokens'] ?? 1024) * 0.5);

        return $this->estimateFromTokens($inputTokens, $outputTokens, $modelAlias);
    }

    public function estimateFromTokens(int $inputTokens, int $outputTokens, string $modelAlias): float
    {
        $pricing = config("llm.claude.pricing.$modelAlias", []);
        $inputPrice = (float) ($pricing['input'] ?? 0.0);
        $outputPrice = (float) ($pricing['output'] ?? 0.0);

        return ($inputTokens * $inputPrice / 1_000_000) + ($outputTokens * $outputPrice / 1_000_000);
    }

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
