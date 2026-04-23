<?php

namespace App\Components\RateLimiter\Claude;

use App\Components\PromptAssembler\DTO\AssembledPayload;

class ClaudeTokenEstimator
{
    private const CHARS_PER_TOKEN = 3.5;
    private const SAFETY_MARGIN = 1.25;

    public function estimate(AssembledPayload $payload): int
    {
        $totalChars = 0;

        // system prompt
        $system = $payload->body['system'] ?? '';
        if (is_string($system)) {
            $totalChars += mb_strlen($system);
        } elseif (is_array($system)) {
            foreach ($system as $block) {
                $totalChars += mb_strlen($block['text'] ?? '');
            }
        }

        // messages
        foreach ($payload->body['messages'] ?? [] as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $totalChars += mb_strlen($content);
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (is_string($block)) {
                        $totalChars += mb_strlen($block);
                    } else {
                        $type = $block['type'] ?? '';
                        $totalChars += mb_strlen($block['text'] ?? '');
                        if ($type === 'tool_result' && isset($block['content'])) {
                            $totalChars += mb_strlen(json_encode($block['content'], JSON_UNESCAPED_UNICODE));
                        }
                    }
                }
            }
        }

        // tools definitions
        if (isset($payload->body['tools'])) {
            $totalChars += mb_strlen(json_encode($payload->body['tools'], JSON_UNESCAPED_UNICODE));
        }

        $estimated = (int) ceil($totalChars / self::CHARS_PER_TOKEN);

        return (int) ceil($estimated * self::SAFETY_MARGIN);
    }
}
