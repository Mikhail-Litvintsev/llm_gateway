<?php

namespace App\Components\PromptAssembler\Contracts;

use App\Components\PromptAssembler\DTO\AssembledPayload;

interface ProviderFormatterContract
{
    public function format(
        string $systemPrompt,
        array $messages,
        ?array $tools,
        array $parameters,
        string $model,
    ): AssembledPayload;
}
