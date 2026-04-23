<?php

namespace App\Components\PromptAssembler;

use App\Components\RequestPipeline\DTO\ToolDefinition;
use App\Components\RequestPipeline\DTO\ToolParam;
use App\Components\RequestPipeline\DTO\ToolsConfig;

class ToolSchemaBuilder
{
    public function build(ToolsConfig $toolsConfig, string $providerName): array
    {
        $tools = [];
        foreach ($toolsConfig->tools as $tool) {
            $tools[] = match ($providerName) {
                'claude' => $this->buildClaudeTool($tool),
                'openai', 'deepseek', 'mistral' => $this->buildOpenAiTool($tool),
                'gemini' => $this->buildGeminiTool($tool),
                default => $this->buildClaudeTool($tool),
            };
        }

        return $tools;
    }

    public function buildToolChoice(ToolsConfig $toolsConfig, string $providerName): mixed
    {
        $choice = $toolsConfig->toolChoice;

        return match ($providerName) {
            'claude' => $this->buildClaudeToolChoice($choice),
            'openai', 'deepseek', 'mistral' => $this->buildOpenAiToolChoice($choice),
            'gemini' => $this->buildGeminiToolChoice($choice),
            default => null,
        };
    }

    private function buildClaudeTool(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $this->buildParamsSchema($tool->params),
        ];
    }

    private function buildOpenAiTool(ToolDefinition $tool): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $this->buildParamsSchema($tool->params),
            ],
        ];
    }

    private function buildGeminiTool(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $this->buildParamsSchema($tool->params),
        ];
    }

    /** @param ToolParam[] $params */
    private function buildParamsSchema(array $params): array
    {
        $properties = [];
        $required = [];

        foreach ($params as $param) {
            $properties[$param->name] = $this->buildParamProperty($param);
            if ($param->required) {
                $required[] = $param->name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function buildParamProperty(ToolParam $param): array
    {
        $prop = ['type' => $param->type];

        if ($param->description) {
            $prop['description'] = $param->description;
        }

        if ($param->enum) {
            $prop['enum'] = json_decode($param->enum, true) ?: explode(',', $param->enum);
        }

        if ($param->default !== null) {
            $prop['default'] = $param->default;
        }

        if ($param->type === 'array' && $param->items) {
            $prop['items'] = json_decode($param->items, true) ?: ['type' => $param->items];
        }

        if ($param->type === 'object' && !empty($param->nestedParams)) {
            $prop = array_merge($prop, $this->buildParamsSchema($param->nestedParams));
        }

        if ($param->type === 'array' && !empty($param->nestedParams)) {
            $prop['items'] = $this->buildParamsSchema($param->nestedParams);
        }

        return $prop;
    }

    private function buildClaudeToolChoice(string $choice): array
    {
        return match ($choice) {
            'auto' => ['type' => 'auto'],
            'none' => ['type' => 'any'],
            'required' => ['type' => 'any'],
            default => ['type' => 'tool', 'name' => $choice],
        };
    }

    private function buildOpenAiToolChoice(string $choice): string|array
    {
        return match ($choice) {
            'auto' => 'auto',
            'none' => 'none',
            'required' => 'required',
            default => ['type' => 'function', 'function' => ['name' => $choice]],
        };
    }

    private function buildGeminiToolChoice(string $choice): ?array
    {
        return match ($choice) {
            'auto' => ['functionCallingConfig' => ['mode' => 'AUTO']],
            'none' => ['functionCallingConfig' => ['mode' => 'NONE']],
            'required' => ['functionCallingConfig' => ['mode' => 'ANY']],
            default => ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => [$choice]]],
        };
    }
}
