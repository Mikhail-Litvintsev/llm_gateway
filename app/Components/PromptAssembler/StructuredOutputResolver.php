<?php

namespace App\Components\PromptAssembler;

use App\Components\PromptAssembler\Enums\StructuredOutputSupport;
use App\Components\RequestPipeline\DTO\ResponseFormatConfig;

class StructuredOutputResolver
{
    public function resolveSupport(string $providerName): StructuredOutputSupport
    {
        return match ($providerName) {
            'openai', 'claude', 'gemini', 'mistral' => StructuredOutputSupport::Native,
            'deepseek' => StructuredOutputSupport::JsonObjectOnly,
            default => StructuredOutputSupport::None,
        };
    }

    public function needsFallbackEmulation(string $providerName, ResponseFormatConfig $format): bool
    {
        $support = $this->resolveSupport($providerName);

        if ($format->type === 'json_schema') {
            return $support !== StructuredOutputSupport::Native;
        }

        if ($format->type === 'json_object') {
            return $support !== StructuredOutputSupport::Native
                && $support !== StructuredOutputSupport::JsonObjectOnly;
        }

        return false;
    }
}
