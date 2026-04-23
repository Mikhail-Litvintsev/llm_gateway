<?php

namespace App\Components\PromptAssembler;

use App\Components\RequestPipeline\DTO\GenerationParameters;
use App\Components\RequestPipeline\DTO\ResponseFormatConfig;

class ParameterMapper
{
    public function map(GenerationParameters $params, string $providerName): array
    {
        return match ($providerName) {
            'claude' => $this->mapClaude($params),
            'openai', 'deepseek', 'mistral' => $this->mapOpenAi($params, $providerName),
            'gemini' => $this->mapGemini($params),
            default => $this->mapClaude($params),
        };
    }

    private function mapClaude(GenerationParameters $params): array
    {
        $mapped = [];

        $mapped['max_tokens'] = $params->maxTokens ?? config('llm.providers.claude.default_max_tokens', 4096);

        if ($params->reasoning?->enabled) {
            // Claude requires temperature=1.0 when reasoning is enabled
            $mapped['temperature'] = 1.0;
            $mapped['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $params->reasoning->maxTokens ?? ($mapped['max_tokens'] - 100),
            ];
        } else {
            if ($params->temperature !== null) {
                $mapped['temperature'] = $params->temperature;
            }
        }

        if ($params->topP !== null) {
            $mapped['top_p'] = $params->topP;
        }

        if ($params->topK !== null) {
            $mapped['top_k'] = $params->topK;
        }

        if ($params->stopSequences) {
            $mapped['stop_sequences'] = $params->stopSequences;
        }

        if ($params->responseFormat) {
            $formatConfig = $this->mapClaudeResponseFormat($params->responseFormat);
            if ($formatConfig !== null) {
                $mapped['output_config'] = $formatConfig;
            }
        }

        // Extra parameters passed as-is
        foreach ($params->extra as $key => $value) {
            $mapped[$key] = $value;
        }

        return $mapped;
    }

    private function mapOpenAi(GenerationParameters $params, string $providerName): array
    {
        $mapped = [];

        if ($params->temperature !== null) {
            $mapped['temperature'] = $params->temperature;
        }

        if ($params->maxTokens !== null) {
            $mapped['max_tokens'] = $params->maxTokens;
        }

        if ($params->topP !== null) {
            $mapped['top_p'] = $params->topP;
        }

        if ($params->stopSequences) {
            $mapped['stop'] = $params->stopSequences;
        }

        if ($params->responseFormat) {
            if ($providerName === 'deepseek' && $params->responseFormat->type === 'json_schema') {
                $mapped['response_format'] = ['type' => 'json_object'];
            } else {
                $mapped['response_format'] = $this->mapOpenAiResponseFormat($params->responseFormat);
            }
        }

        if ($params->reasoning?->enabled && $params->reasoning->effort) {
            $mapped['reasoning_effort'] = $params->reasoning->effort;
        }

        foreach ($params->extra as $key => $value) {
            $mapped[$key] = $value;
        }

        return $mapped;
    }

    private function mapGemini(GenerationParameters $params): array
    {
        $mapped = [];

        if ($params->temperature !== null) {
            $mapped['generationConfig']['temperature'] = $params->temperature;
        }

        if ($params->maxTokens !== null) {
            $mapped['generationConfig']['maxOutputTokens'] = $params->maxTokens;
        }

        if ($params->topP !== null) {
            $mapped['generationConfig']['topP'] = $params->topP;
        }

        if ($params->topK !== null) {
            $mapped['generationConfig']['topK'] = $params->topK;
        }

        if ($params->stopSequences) {
            $mapped['generationConfig']['stopSequences'] = $params->stopSequences;
        }

        if ($params->responseFormat) {
            $mapped['generationConfig'] = array_merge(
                $mapped['generationConfig'] ?? [],
                $this->mapGeminiResponseFormat($params->responseFormat),
            );
        }

        foreach ($params->extra as $key => $value) {
            $mapped[$key] = $value;
        }

        return $mapped;
    }

    private function mapClaudeResponseFormat(ResponseFormatConfig $format): ?array
    {
        if ($format->type === 'json_schema' && $format->schema) {
            $schema = json_decode($format->schema, true) ?: [];
            return [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $schema,
                ],
            ];
        }

        return null;
    }

    private function mapGeminiResponseFormat(ResponseFormatConfig $format): array
    {
        if ($format->type === 'json_schema' && $format->schema) {
            $schema = json_decode($format->schema, true) ?: [];
            $schema = $this->removeAdditionalProperties($schema);
            return [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ];
        }

        if ($format->type === 'json_object') {
            return [
                'responseMimeType' => 'application/json',
            ];
        }

        return [];
    }

    private function removeAdditionalProperties(array $schema): array
    {
        unset($schema['additionalProperties']);

        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = $this->removeAdditionalProperties($prop);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->removeAdditionalProperties($schema['items']);
        }

        return $schema;
    }

    private function mapOpenAiResponseFormat(ResponseFormatConfig $format): array
    {
        if ($format->type === 'json_schema' && $format->schema) {
            return [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $format->name ?? 'response',
                    'strict' => $format->strict ?? false,
                    'schema' => json_decode($format->schema, true) ?: [],
                ],
            ];
        }

        return ['type' => $format->type];
    }
}

