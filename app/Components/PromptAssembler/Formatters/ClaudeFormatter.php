<?php

namespace App\Components\PromptAssembler\Formatters;

use App\Components\PromptAssembler\Contracts\ProviderFormatterContract;
use App\Components\PromptAssembler\DTO\AssembledPayload;

class ClaudeFormatter implements ProviderFormatterContract
{
    public function format(
        string $systemPrompt,
        array $messages,
        ?array $tools,
        array $parameters,
        string $model,
    ): AssembledPayload {
        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }

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
