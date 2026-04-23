<?php

namespace App\Components\PromptAssembler\Formatters;

use App\Components\PromptAssembler\Contracts\ProviderFormatterContract;
use App\Components\PromptAssembler\DTO\AssembledPayload;

class OpenAiFormatter implements ProviderFormatterContract
{
    public function format(
        string $systemPrompt,
        array $messages,
        ?array $tools,
        array $parameters,
        string $model,
    ): AssembledPayload {
        $allMessages = [];

        if ($systemPrompt !== '') {
            $allMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            $allMessages[] = $message;
        }

        $body = [
            'model' => $model,
            'messages' => $allMessages,
        ];

        if ($tools) {
            $body['tools'] = $tools;
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
