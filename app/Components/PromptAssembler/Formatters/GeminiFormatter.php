<?php

namespace App\Components\PromptAssembler\Formatters;

use App\Components\PromptAssembler\Contracts\ProviderFormatterContract;
use App\Components\PromptAssembler\DTO\AssembledPayload;

class GeminiFormatter implements ProviderFormatterContract
{
    public function format(
        string $systemPrompt,
        array $messages,
        ?array $tools,
        array $parameters,
        string $model,
    ): AssembledPayload {
        $contents = [];

        if ($systemPrompt !== '') {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => "[System]\n{$systemPrompt}"]],
            ];
            $contents[] = [
                'role' => 'model',
                'parts' => [['text' => 'Understood.']],
            ];
        }

        foreach ($messages as $message) {
            $role = match ($message['role']) {
                'assistant' => 'model',
                'tool' => 'function',
                default => $message['role'],
            };

            $parts = [];
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $parts[] = ['text' => $content];
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (isset($block['text'])) {
                        $parts[] = ['text' => $block['text']];
                    } elseif (isset($block['inlineData'])) {
                        $parts[] = $block;
                    } elseif (isset($block['type']) && $block['type'] === 'text') {
                        $parts[] = ['text' => $block['text'] ?? ''];
                    }
                }
            }

            $contents[] = ['role' => $role, 'parts' => $parts];
        }

        $body = ['contents' => $contents];

        if ($tools) {
            $body['tools'] = [['functionDeclarations' => $tools]];
        }

        $body = array_merge($body, $parameters);

        return new AssembledPayload(
            body: $body,
            headers: [
                'content-type' => 'application/json',
            ],
        );
    }
}
