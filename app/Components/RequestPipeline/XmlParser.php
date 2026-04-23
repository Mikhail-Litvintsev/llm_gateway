<?php

namespace App\Components\RequestPipeline;

use App\Components\RequestPipeline\DTO\CallbackConfig;
use App\Components\RequestPipeline\DTO\GenerationParameters;
use App\Components\RequestPipeline\DTO\MetaData;
use App\Components\RequestPipeline\DTO\ParsedRequest;
use App\Components\RequestPipeline\DTO\PromptBlock;
use App\Components\RequestPipeline\DTO\ProviderConfig;
use App\Components\RequestPipeline\DTO\ReasoningConfig;
use App\Components\RequestPipeline\DTO\ResponseFormatConfig;
use App\Components\RequestPipeline\DTO\RetryConfig;
use App\Components\RequestPipeline\DTO\ToolDefinition;
use App\Components\RequestPipeline\DTO\ToolParam;
use App\Components\RequestPipeline\DTO\ToolsConfig;
use App\Components\RequestPipeline\Exceptions\XmlParseException;

class XmlParser
{
    public function parse(string $xmlString): ParsedRequest
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, \SimpleXMLElement::class, LIBXML_NOCDATA);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new XmlParseException(
                'INVALID_XML',
                'Failed to parse XML: ' . ($errors[0]->message ?? 'Unknown error'),
            );
        }

        if ($xml->getName() !== 'llm_request') {
            throw new XmlParseException(
                'INVALID_XML',
                'Root element must be <llm_request>.',
            );
        }

        $version = (string) ($xml['version'] ?? '');
        if ($version !== '3.0') {
            throw new XmlParseException(
                'VERSION_NOT_SUPPORTED',
                "Version '{$version}' is not supported. Expected '3.0'.",
            );
        }

        if (!isset($xml->meta)) {
            throw new XmlParseException('MISSING_REQUIRED_SECTION', 'Required section <meta> is missing.');
        }
        if (!isset($xml->prompt)) {
            throw new XmlParseException('MISSING_REQUIRED_SECTION', 'Required section <prompt> is missing.');
        }
        if (!isset($xml->callback)) {
            throw new XmlParseException('MISSING_REQUIRED_SECTION', 'Required section <callback> is missing.');
        }

        $meta = $this->parseMeta($xml->meta);
        $provider = isset($xml->provider) ? $this->parseProvider($xml->provider) : null;
        $blocks = $this->parsePrompt($xml->prompt);
        $tools = isset($xml->tools) ? $this->parseTools($xml->tools) : null;
        $parameters = isset($xml->parameters) ? $this->parseParameters($xml->parameters) : null;
        $callback = $this->parseCallback($xml->callback);

        $rawPromptXml = $xml->prompt->asXML();
        $rawToolsXml = isset($xml->tools) ? $xml->tools->asXML() : null;
        $rawParametersXml = isset($xml->parameters) ? $xml->parameters->asXML() : null;
        $rawProviderXml = isset($xml->provider) ? $xml->provider->asXML() : null;

        return new ParsedRequest(
            version: $version,
            meta: $meta,
            provider: $provider,
            blocks: $blocks,
            tools: $tools,
            parameters: $parameters,
            callback: $callback,
            rawPromptXml: $rawPromptXml,
            rawToolsXml: $rawToolsXml,
            rawParametersXml: $rawParametersXml,
            rawProviderXml: $rawProviderXml,
        );
    }

    private function parseMeta(\SimpleXMLElement $meta): MetaData
    {
        $requestId = (string) ($meta->request_id ?? '');
        if ($requestId === '') {
            throw new XmlParseException('MISSING_REQUEST_ID', 'Required field <request_id> is missing in <meta>.');
        }

        $knownFields = ['request_id', 'session_id', 'step_id', 'timestamp', 'source', 'user_id', 'priority'];
        $extraFields = [];

        foreach ($meta->children() as $child) {
            $name = $child->getName();
            if (!in_array($name, $knownFields, true)) {
                $extraFields[$name] = (string) $child;
            }
        }

        return new MetaData(
            requestId: $requestId,
            sessionId: $this->optionalString($meta->session_id),
            stepId: isset($meta->step_id) ? (int) (string) $meta->step_id : null,
            timestamp: $this->optionalString($meta->timestamp),
            source: $this->optionalString($meta->source),
            userId: $this->optionalString($meta->user_id),
            priority: $this->optionalString($meta->priority),
            extraFields: $extraFields,
        );
    }

    private function parseProvider(\SimpleXMLElement $provider): ProviderConfig
    {
        $fallback = isset($provider->fallback) ? $this->parseProvider($provider->fallback) : null;

        return new ProviderConfig(
            name: $this->optionalString($provider->name),
            model: $this->optionalString($provider->model),
            fallback: $fallback,
        );
    }

    /** @return PromptBlock[] */
    private function parsePrompt(\SimpleXMLElement $prompt): array
    {
        $blocks = [];
        foreach ($prompt->block as $block) {
            $blocks[] = $this->parseBlock($block);
        }
        return $blocks;
    }

    private function parseBlock(\SimpleXMLElement $block): PromptBlock
    {
        return new PromptBlock(
            type: (string) ($block['type'] ?? 'data'),
            role: (string) ($block['role'] ?? 'user'),
            id: $this->optionalAttr($block, 'id'),
            label: $this->optionalAttr($block, 'label'),
            format: $this->optionalAttr($block, 'format'),
            mediaType: $this->optionalAttr($block, 'media_type'),
            for: $this->optionalAttr($block, 'for'),
            toolCallId: $this->optionalAttr($block, 'tool_call_id'),
            cache: filter_var((string) ($block['cache'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
            content: trim((string) $block),
        );
    }

    private function parseTools(\SimpleXMLElement $tools): ToolsConfig
    {
        $toolChoice = (string) ($tools['tool_choice'] ?? 'auto');

        $toolDefinitions = [];
        foreach ($tools->tool as $tool) {
            $toolDefinitions[] = $this->parseTool($tool);
        }

        return new ToolsConfig(
            toolChoice: $toolChoice,
            tools: $toolDefinitions,
        );
    }

    private function parseTool(\SimpleXMLElement $tool): ToolDefinition
    {
        $params = [];
        if (isset($tool->params)) {
            foreach ($tool->params->param as $param) {
                $params[] = $this->parseParam($param);
            }
        }

        return new ToolDefinition(
            name: (string) ($tool->name ?? ''),
            description: (string) ($tool->description ?? ''),
            params: $params,
        );
    }

    private function parseParam(\SimpleXMLElement $param): ToolParam
    {
        $nestedParams = [];
        if (isset($param->params)) {
            foreach ($param->params->param as $nested) {
                $nestedParams[] = $this->parseParam($nested);
            }
        }

        return new ToolParam(
            name: (string) ($param['name'] ?? ''),
            type: (string) ($param['type'] ?? 'string'),
            required: filter_var((string) ($param['required'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
            description: $this->optionalString($param->description),
            enum: $this->optionalAttr($param, 'enum'),
            default: $this->optionalAttr($param, 'default'),
            items: $this->optionalString($param->items),
            properties: $this->optionalString($param->properties),
            nestedParams: $nestedParams,
        );
    }

    private function parseParameters(\SimpleXMLElement $params): GenerationParameters
    {
        $stopSequences = null;
        if (isset($params->stop_sequences)) {
            $decoded = json_decode((string) $params->stop_sequences, true);
            $stopSequences = is_array($decoded) ? $decoded : null;
        }

        $extra = [];
        if (isset($params->extra)) {
            foreach ($params->extra->param as $p) {
                $extra[(string) ($p['name'] ?? '')] = (string) $p;
            }
        }

        return new GenerationParameters(
            temperature: isset($params->temperature) ? (float) (string) $params->temperature : null,
            maxTokens: isset($params->max_tokens) ? (int) (string) $params->max_tokens : null,
            topP: isset($params->top_p) ? (float) (string) $params->top_p : null,
            topK: isset($params->top_k) ? (int) (string) $params->top_k : null,
            stopSequences: $stopSequences,
            responseFormat: isset($params->response_format) ? $this->parseResponseFormat($params->response_format) : null,
            stream: filter_var((string) ($params->stream ?? 'false'), FILTER_VALIDATE_BOOLEAN),
            reasoning: isset($params->reasoning) ? $this->parseReasoning($params->reasoning) : null,
            extra: $extra,
        );
    }

    private function parseReasoning(\SimpleXMLElement $reasoning): ReasoningConfig
    {
        return new ReasoningConfig(
            enabled: filter_var((string) ($reasoning->enabled ?? 'false'), FILTER_VALIDATE_BOOLEAN),
            effort: $this->optionalString($reasoning->effort),
            maxTokens: isset($reasoning->max_tokens) ? (int) (string) $reasoning->max_tokens : null,
        );
    }

    private function parseResponseFormat(\SimpleXMLElement $rf): ResponseFormatConfig
    {
        // Can be simple string or complex element
        if (!$rf->count()) {
            // Simple value like <response_format>json_object</response_format>
            return new ResponseFormatConfig(
                type: (string) $rf,
                name: null,
                strict: null,
                schema: null,
            );
        }

        return new ResponseFormatConfig(
            type: (string) ($rf->type ?? 'text'),
            name: $this->optionalString($rf->name),
            strict: isset($rf->strict) ? filter_var((string) $rf->strict, FILTER_VALIDATE_BOOLEAN) : null,
            schema: $this->optionalString($rf->schema),
        );
    }

    private function parseCallback(\SimpleXMLElement $callback): CallbackConfig
    {
        $url = (string) ($callback->url ?? '');
        if ($url === '') {
            throw new XmlParseException('MISSING_CALLBACK_URL', 'Required field <url> is missing in <callback>.');
        }

        $headers = [];
        if (isset($callback->headers)) {
            foreach ($callback->headers->header as $header) {
                $headers[(string) ($header['name'] ?? '')] = (string) $header;
            }
        }

        $retry = isset($callback->retry) ? $this->parseRetry($callback->retry) : $this->defaultRetry();

        return new CallbackConfig(
            url: $url,
            method: strtoupper((string) ($callback->method ?? 'POST')),
            headers: $headers,
            timeout: isset($callback->timeout) ? (int) (string) $callback->timeout : 300,
            retry: $retry,
        );
    }

    private function parseRetry(\SimpleXMLElement $retry): RetryConfig
    {
        return new RetryConfig(
            maxAttempts: isset($retry->max_attempts) ? (int) (string) $retry->max_attempts : 3,
            backoff: (string) ($retry->backoff ?? 'exponential'),
            initialDelay: isset($retry->initial_delay) ? (int) (string) $retry->initial_delay : 1,
        );
    }

    private function defaultRetry(): RetryConfig
    {
        return new RetryConfig(maxAttempts: 3, backoff: 'exponential', initialDelay: 1);
    }

    private function optionalString(?\SimpleXMLElement $element): ?string
    {
        if ($element === null || !isset($element[0])) {
            return null;
        }
        $value = (string) $element;
        return $value === '' ? null : $value;
    }

    private function optionalAttr(\SimpleXMLElement $element, string $attr): ?string
    {
        return isset($element[$attr]) ? (string) $element[$attr] : null;
    }
}
