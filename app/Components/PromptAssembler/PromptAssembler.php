<?php

namespace App\Components\PromptAssembler;

use App\Components\PromptAssembler\Contracts\ProviderFormatterContract;
use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\PromptAssembler\Formatters\ClaudeFormatter;
use App\Components\PromptAssembler\Formatters\GeminiFormatter;
use App\Components\PromptAssembler\Formatters\OpenAiFormatter;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\RequestPipeline\DTO\ParsedRequest;

class PromptAssembler
{
    public function __construct(
        private readonly BlockAssembler $blockAssembler,
        private readonly ToolSchemaBuilder $toolSchemaBuilder,
        private readonly ParameterMapper $parameterMapper,
        private readonly ClaudeFormatter $claudeFormatter,
        private readonly OpenAiFormatter $openAiFormatter,
        private readonly GeminiFormatter $geminiFormatter,
        private readonly StructuredOutputResolver $structuredOutputResolver,
        private readonly StructuredOutputFallback $structuredOutputFallback,
    ) {}

    public function assemble(ParsedRequest $parsed, ResolvedProvider $provider): AssembledPayload
    {
        // 1. Assemble blocks into system prompt + messages
        $assembled = $this->blockAssembler->assemble($parsed->blocks, $provider->providerName);

        // 1.5. Structured output fallback injection
        $structuredOutputFallback = false;
        if ($parsed->parameters?->responseFormat) {
            $needsFallback = $this->structuredOutputResolver->needsFallbackEmulation(
                $provider->providerName,
                $parsed->parameters->responseFormat,
            );
            if ($needsFallback) {
                $assembled = $assembled->withSystemPrompt(
                    $this->structuredOutputFallback->injectSchemaIntoSystemPrompt(
                        $assembled->systemPrompt,
                        $parsed->parameters->responseFormat,
                    )
                );
                $structuredOutputFallback = true;
            }
        }

        // 2. Build tools schema if present
        $tools = null;
        $toolChoice = null;
        if ($parsed->tools) {
            $tools = $this->toolSchemaBuilder->build($parsed->tools, $provider->providerName);
            $toolChoice = $this->toolSchemaBuilder->buildToolChoice($parsed->tools, $provider->providerName);
        }

        // 3. Map parameters
        $parameters = $parsed->parameters
            ? $this->parameterMapper->map($parsed->parameters, $provider->providerName)
            : [];

        // Add tool_choice to parameters if applicable
        if ($toolChoice !== null) {
            $parameters = match ($provider->providerName) {
                'claude' => array_merge($parameters, ['tool_choice' => $toolChoice]),
                'openai', 'deepseek', 'mistral' => array_merge($parameters, ['tool_choice' => $toolChoice]),
                'gemini' => array_merge($parameters, ['toolConfig' => $toolChoice]),
                default => $parameters,
            };
        }

        // 4. Select formatter and build payload
        $formatter = $this->resolveFormatter($provider->providerName);

        $payload = $formatter->format(
            $assembled->systemPrompt,
            $assembled->messages,
            $tools,
            $parameters,
            $provider->modelName,
        );

        if ($structuredOutputFallback) {
            $payload = new AssembledPayload(
                body: $payload->body,
                headers: $payload->headers,
                structuredOutputFallback: true,
            );
        }

        return $payload;
    }

    private function resolveFormatter(string $providerName): ProviderFormatterContract
    {
        return match ($providerName) {
            'claude' => $this->claudeFormatter,
            'openai', 'deepseek', 'mistral' => $this->openAiFormatter,
            'gemini' => $this->geminiFormatter,
            default => throw new \RuntimeException("No formatter for provider: $providerName"),
        };
    }
}
