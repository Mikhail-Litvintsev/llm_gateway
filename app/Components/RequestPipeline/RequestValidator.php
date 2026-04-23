<?php

namespace App\Components\RequestPipeline;

use App\Components\Auth\ApiAuthenticator;
use App\Components\Auth\DTO\AuthenticatedClient;
use App\Components\RequestPipeline\DTO\ParsedRequest;
use App\Components\RequestPipeline\DTO\PromptBlock;
use App\Components\RequestPipeline\DTO\ProviderConfig;
use App\Components\RequestPipeline\Enums\BlockFormat;
use App\Components\RequestPipeline\Enums\BlockType;
use App\Components\RequestPipeline\Exceptions\ValidationException;

class RequestValidator
{
    private const ALLOWED_TYPE_ROLES = [
        'system'              => ['system'],
        'instruction'         => ['user'],
        'description'         => ['user'],
        'data'                => ['user'],
        'example'             => ['user', 'assistant'],
        'constraint'          => ['system', 'user'],
        'output_format'       => ['system', 'user'],
        'image'               => ['user'],
        'document'            => ['user'],
        'audio'               => ['user'],
        'url'                 => ['user'],
        'history'             => ['user', 'assistant'],
        'history_tool_result' => ['tool'],
        'prefix'              => ['assistant'],
    ];

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly SessionTracker $sessionTracker,
    ) {}

    public function validate(ParsedRequest $request, AuthenticatedClient $client): void
    {
        $this->validateVersion($request);
        $this->validateMeta($request, $client);
        $this->validateProvider($request, $client);
        $this->validateBlocks($request);
        $this->validateParameters($request);
        $this->validateCallback($request, $client);
    }

    private function validateVersion(ParsedRequest $request): void
    {
        if ($request->version !== '3.0') {
            throw new ValidationException(
                'VERSION_NOT_SUPPORTED',
                "Version '{$request->version}' is not supported.",
            );
        }
    }

    private function validateMeta(ParsedRequest $request, AuthenticatedClient $client): void
    {
        if (empty($request->meta->requestId)) {
            throw new ValidationException('MISSING_REQUEST_ID', 'Field request_id is required in <meta>.');
        }

        if ($request->meta->sessionId !== null && $request->meta->stepId === null) {
            throw new ValidationException('MISSING_STEP_ID', 'Field step_id is required when session_id is provided.');
        }
    }

    private function validateProvider(ParsedRequest $request, AuthenticatedClient $client): void
    {
        if ($request->provider !== null) {
            $this->validateSingleProvider($request->provider, $client);
        }
    }

    private function validateSingleProvider(ProviderConfig $provider, AuthenticatedClient $client): void
    {
        if ($provider->name !== null) {
            $configured = config('llm.providers');
            if (!isset($configured[$provider->name])) {
                throw new ValidationException(
                    'PROVIDER_UNKNOWN',
                    "Provider '{$provider->name}' is not supported.",
                );
            }

            if ($client->allowedProviders !== null && !in_array($provider->name, $client->allowedProviders, true)) {
                throw new ValidationException(
                    'PROVIDER_NOT_ALLOWED',
                    "Client is not allowed to use provider '{$provider->name}'.",
                    403,
                );
            }
        }

        if ($provider->fallback !== null) {
            $this->validateSingleProvider($provider->fallback, $client);
        }
    }

    private function validateBlocks(ParsedRequest $request): void
    {
        $blocks = $request->blocks;

        if (empty($blocks)) {
            throw new ValidationException('MISSING_USER_BLOCK', 'At least one block with role="user" is required.');
        }

        $hasUserBlock = false;
        $ids = [];
        $prefixCount = 0;

        foreach ($blocks as $i => $block) {
            // Validate type
            if (BlockType::tryFrom($block->type) === null) {
                throw new ValidationException(
                    'UNKNOWN_BLOCK_TYPE',
                    "Unknown block type '{$block->type}'.",
                );
            }

            // Validate format
            if ($block->format !== null && BlockFormat::tryFrom($block->format) === null) {
                throw new ValidationException(
                    'UNKNOWN_FORMAT',
                    "Unknown block format '{$block->format}'.",
                );
            }

            // Validate type+role combination
            $allowedRoles = self::ALLOWED_TYPE_ROLES[$block->type] ?? null;
            if ($allowedRoles !== null && !in_array($block->role, $allowedRoles, true)) {
                throw new ValidationException(
                    'INVALID_TYPE_ROLE_COMBINATION',
                    "Block type '{$block->type}' cannot have role '{$block->role}'.",
                );
            }

            // Track user blocks
            if ($block->role === 'user') {
                $hasUserBlock = true;
            }

            // Validate base64 requires media_type
            if ($block->format === 'base64' && $block->mediaType === null) {
                throw new ValidationException(
                    'MISSING_MEDIA_TYPE',
                    'Attribute media_type is required when format is base64.',
                );
            }

            // Validate history_tool_result requires tool_call_id
            if ($block->type === 'history_tool_result' && $block->toolCallId === null) {
                throw new ValidationException(
                    'MISSING_TOOL_CALL_ID',
                    'Attribute tool_call_id is required for history_tool_result blocks.',
                );
            }

            // Track unique IDs
            if ($block->id !== null) {
                if (in_array($block->id, $ids, true)) {
                    throw new ValidationException(
                        'DUPLICATE_BLOCK_ID',
                        "Duplicate block id '{$block->id}'.",
                    );
                }
                $ids[] = $block->id;
            }

            // Prefix count
            if ($block->type === 'prefix') {
                $prefixCount++;
            }
        }

        if (!$hasUserBlock) {
            throw new ValidationException('MISSING_USER_BLOCK', 'At least one block with role="user" is required.');
        }

        if ($prefixCount > 1) {
            throw new ValidationException('INVALID_PARAMETER', 'At most one prefix block is allowed.');
        }

        // Validate description references
        $this->validateDescriptions($blocks, $ids);

        // Validate history ordering
        $this->validateHistoryOrder($blocks);
    }

    /** @param PromptBlock[] $blocks */
    private function validateDescriptions(array $blocks, array $ids): void
    {
        foreach ($blocks as $i => $block) {
            if ($block->type !== 'description') {
                continue;
            }

            if ($block->for !== null) {
                if (!in_array($block->for, $ids, true)) {
                    throw new ValidationException(
                        'DANGLING_DESCRIPTION',
                        "Description block references non-existent id '{$block->for}'.",
                    );
                }
            } else {
                // Next block must be data
                $next = $blocks[$i + 1] ?? null;
                if ($next === null || $next->type !== 'data') {
                    throw new ValidationException(
                        'ORPHAN_DESCRIPTION',
                        'Description block without "for" must be followed by a data block.',
                    );
                }
            }
        }
    }

    /** @param PromptBlock[] $blocks */
    private function validateHistoryOrder(array $blocks): void
    {
        $historyTypes = ['history', 'history_tool_result'];
        $inHistory = false;
        $lastHistoryRole = null;

        foreach ($blocks as $block) {
            $isHistory = in_array($block->type, $historyTypes, true);

            if ($isHistory && !$inHistory) {
                // Starting history section
                $inHistory = true;
                if ($block->type === 'history' && $block->role !== 'user') {
                    throw new ValidationException(
                        'HISTORY_ORDER_VIOLATION',
                        'First history block must have role="user".',
                    );
                }
                $lastHistoryRole = $block->role;
                continue;
            }

            if ($inHistory && !$isHistory) {
                // Exiting history section
                $inHistory = false;
                $lastHistoryRole = null;
                continue;
            }

            if ($inHistory && $isHistory) {
                if ($block->type === 'history_tool_result') {
                    $lastHistoryRole = 'tool';
                    continue;
                }
                // history block — check alternation
                if ($lastHistoryRole === 'user' && $block->role !== 'assistant') {
                    throw new ValidationException(
                        'HISTORY_ORDER_VIOLATION',
                        'History blocks must alternate between user and assistant.',
                    );
                }
                if ($lastHistoryRole === 'assistant' && $block->role !== 'user') {
                    throw new ValidationException(
                        'HISTORY_ORDER_VIOLATION',
                        'History blocks must alternate between user and assistant.',
                    );
                }
                $lastHistoryRole = $block->role;
            }
        }
    }

    private function validateParameters(ParsedRequest $request): void
    {
        $params = $request->parameters;
        if ($params === null) {
            return;
        }

        if ($params->temperature !== null && ($params->temperature < 0.0 || $params->temperature > 2.0)) {
            throw new ValidationException('INVALID_PARAMETER', 'temperature must be between 0.0 and 2.0.');
        }

        if ($params->maxTokens !== null && $params->maxTokens <= 0) {
            throw new ValidationException('INVALID_PARAMETER', 'max_tokens must be a positive integer.');
        }

        if ($params->topP !== null && ($params->topP < 0.0 || $params->topP > 1.0)) {
            throw new ValidationException('INVALID_PARAMETER', 'top_p must be between 0.0 and 1.0.');
        }

        if ($params->stopSequences !== null && count($params->stopSequences) > 4) {
            throw new ValidationException('INVALID_PARAMETER', 'stop_sequences must have at most 4 elements.');
        }

        if ($params->responseFormat !== null) {
            $this->validateResponseFormat($params->responseFormat);
        }

        // Validate retry max_attempts from callback
        $retry = $request->callback->retry;
        if ($retry->maxAttempts < 1 || $retry->maxAttempts > 5) {
            throw new ValidationException('INVALID_PARAMETER', 'retry.max_attempts must be between 1 and 5.');
        }
    }

    private function validateResponseFormat(\App\Components\RequestPipeline\DTO\ResponseFormatConfig $format): void
    {
        $allowedTypes = ['text', 'json_object', 'json_schema'];
        if (!in_array($format->type, $allowedTypes, true)) {
            throw new ValidationException(
                'INVALID_PARAMETER',
                'response_format.type must be one of: text, json_object, json_schema.',
            );
        }

        if ($format->type !== 'json_schema') {
            return;
        }

        if ($format->schema === null || $format->schema === '') {
            throw new ValidationException(
                'INVALID_PARAMETER',
                'response_format.schema is required when type is json_schema.',
            );
        }

        $decoded = json_decode($format->schema, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException(
                'INVALID_PARAMETER',
                'response_format.schema must be valid JSON.',
            );
        }

        if (!is_array($decoded) || !isset($decoded['type'])) {
            throw new ValidationException(
                'INVALID_PARAMETER',
                "response_format.schema must be a JSON Schema object with 'type' property.",
            );
        }

        if ($format->name === null || $format->name === '') {
            throw new ValidationException(
                'INVALID_PARAMETER',
                'response_format.name is required when type is json_schema.',
            );
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $format->name) || mb_strlen($format->name) > 64) {
            throw new ValidationException(
                'INVALID_PARAMETER',
                'response_format.name must match pattern ^[a-zA-Z_][a-zA-Z0-9_-]*$ and be at most 64 characters.',
            );
        }
    }

    private function validateCallback(ParsedRequest $request, AuthenticatedClient $client): void
    {
        $callback = $request->callback;

        if (empty($callback->url)) {
            throw new ValidationException('MISSING_CALLBACK_URL', 'Callback URL is required.');
        }

        if (!in_array($callback->method, ['POST', 'PUT'], true)) {
            throw new ValidationException('INVALID_PARAMETER', 'Callback method must be POST or PUT.');
        }

        $this->authenticator->validateCallbackUrl($client->id, $callback->url);
    }
}
