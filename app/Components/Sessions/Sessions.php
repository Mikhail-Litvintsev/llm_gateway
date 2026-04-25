<?php

declare(strict_types=1);

namespace App\Components\Sessions;

use App\Components\Authorization\Authorization;
use App\Components\Billing\CostEstimator;
use App\Components\Claude\Claude;
use App\Components\Claude\DTO\MessageResponse;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Claude\Response\ResponseParser;
use App\Components\Claude\ToolTypeCatalog;
use App\Components\Routing\Exceptions\UnknownModelAliasException;
use App\Components\Routing\ModelResolver;
use App\Components\Routing\WorkspaceResolver;
use App\Components\Sessions\Contracts\SessionsContract;
use App\Components\Sessions\Contracts\SessionStoreContract;
use App\Components\Sessions\DTO\MemoryCommandResult;
use App\Components\Sessions\DTO\SessionCreateInput;
use App\Components\Sessions\DTO\SessionHistoryPage;
use App\Components\Sessions\DTO\SessionMetadata;
use App\Components\Sessions\DTO\SessionSendMessageInput;
use App\Components\Sessions\DTO\SessionSendMessageResult;
use App\Components\Sessions\Exceptions\SessionExpiredException;
use App\Components\Sessions\Exceptions\SessionNotFoundException;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Models\Client;
use App\Models\Session;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class Sessions implements SessionsContract
{
    public function __construct(
        private SessionStoreContract $store,
        private Claude $claude,
        private PayloadBuilder $payloadBuilder,
        private ResponseParser $responseParser,
        private MessageRequestValidator $validator,
        private Authorization $authorization,
        private MemoryHandler $memoryHandler,
        private WorkspaceResolver $workspaceResolver,
        private ModelResolver $modelResolver,
        private CostEstimator $costEstimator,
    ) {}

    public function create(SessionCreateInput $input): SessionMetadata
    {
        try {
            $this->modelResolver->resolve($input->modelAlias);
        } catch (UnknownModelAliasException $e) {
            throw new RuntimeException($e->getMessage(), 422);
        }

        $client = Client::findOrFail($input->clientId);
        $featuresUsed = $this->extractFeaturesFromTools($input->tools);

        $authResult = $this->authorization->authorize($client, $input->modelAlias, $featuresUsed);
        if (! $authResult->allowed) {
            throw new RuntimeException($authResult->message ?? 'Authorization denied', 403);
        }

        if ($input->mcpServers !== null) {
            $allowedFeatures = $client->allowed_features ?? [];
            if (! ($allowedFeatures['mcp_connector'] ?? false)) {
                throw new RuntimeException('MCP connector is not enabled for this client', 403);
            }
        }

        $adjusted = new SessionCreateInput(
            clientId: $input->clientId,
            workspaceId: $input->workspaceId,
            modelAlias: $input->modelAlias,
            system: $input->system,
            tools: $input->tools,
            cacheStrategy: $input->cacheStrategy,
            contextManagement: $input->contextManagement,
            autoResume: $input->autoResume,
            expiresAt: $input->expiresAt ?? new DateTimeImmutable(
                '+'.config('llm.sessions.default_ttl_hours', 336).' hours'
            ),
            mcpServers: $input->mcpServers,
        );

        return $this->store->create($adjusted);
    }

    public function getMetadata(string $publicId): SessionMetadata
    {
        return $this->store->getMetadata($this->resolveSession($publicId));
    }

    public function paginateHistory(string $publicId, int $from, int $limit): SessionHistoryPage
    {
        return $this->store->paginateHistory($this->resolveSession($publicId), $from, $limit);
    }

    public function sendSync(string $publicId, SessionSendMessageInput $input): SessionSendMessageResult
    {
        [$session, $client, $featuresUsed] = $this->prepareSendContext($publicId);

        $history = $this->store->loadFullHistory($session);
        $messages = [...$history, ['role' => 'user', 'content' => $input->newUserContent]];

        $payload = $this->buildPayload($session, $messages, $input);
        $this->validatePayload($payload, $client);
        $this->store->appendUserMessage($session, $input->newUserContent);

        $warnings = [];
        $maxMemoryIterations = (int) config('llm.sessions.memory_tool_max_iterations', 5);
        $maxPauseTurnIterations = (int) config('llm.sessions.pause_turn_max_iterations', 5);

        $response = $this->callClaudeSync($payload, $client, $featuresUsed);
        $assistantContent = $response->content;
        $stopReason = $response->stopReason ?? 'end_turn';
        $usage = $response->usage;
        $model = $response->model;

        $this->store->appendAssistantMessage($session, $assistantContent, $stopReason, $usage, $model);
        $this->handleCompaction($session, $assistantContent);

        $iteration = 0;
        while ($iteration < $maxMemoryIterations && $this->hasMemoryToolUse($assistantContent)) {
            $iteration++;
            $toolResults = $this->dispatchMemoryTools($session, $assistantContent);
            $this->store->appendUserMessage($session, $toolResults);

            $extHistory = $this->store->loadFullHistory($session);
            $payload = $this->buildPayload($session, $extHistory, $input);

            $response = $this->callClaudeSync($payload, $client, $featuresUsed);
            $assistantContent = $response->content;
            $stopReason = $response->stopReason ?? 'end_turn';
            $usage = $response->usage;
            $model = $response->model;

            $this->store->appendAssistantMessage($session, $assistantContent, $stopReason, $usage, $model);
            $this->handleCompaction($session, $assistantContent);
        }

        if ($this->hasMemoryToolUse($assistantContent)) {
            $warnings[] = 'sessions.memory_tool_iteration_limit';
        }

        if ($session->auto_resume) {
            $pauseIteration = 0;
            while ($pauseIteration < $maxPauseTurnIterations && $stopReason === 'pause_turn') {
                $pauseIteration++;

                $extHistory = $this->store->loadFullHistory($session);
                $payload = $this->buildPayload($session, $extHistory, $input);

                $response = $this->callClaudeSync($payload, $client, $featuresUsed);
                $assistantContent = $response->content;
                $stopReason = $response->stopReason ?? 'end_turn';
                $usage = $response->usage;
                $model = $response->model;

                $this->store->appendAssistantMessage($session, $assistantContent, $stopReason, $usage, $model);
                $this->handleCompaction($session, $assistantContent);

                if ($this->hasMemoryToolUse($assistantContent)) {
                    break;
                }
            }

            if ($stopReason === 'pause_turn') {
                $warnings[] = 'auto_resume_limit_reached';
            }
        }

        $metadata = $this->store->getMetadata($session->refresh());

        return new SessionSendMessageResult(
            publicId: $metadata->publicId,
            messageCount: $metadata->messageCount,
            totalCostUsd: $metadata->totalCostUsd,
            stopReason: $stopReason,
            assistantContent: $assistantContent,
            usage: $usage,
            model: $model,
            warnings: $warnings,
        );
    }

    public function sendStream(string $publicId, SessionSendMessageInput $input): Generator
    {
        [$session, $client] = $this->prepareSendContext($publicId);

        $history = $this->store->loadFullHistory($session);
        $messages = [...$history, ['role' => 'user', 'content' => $input->newUserContent]];

        $payload = $this->buildPayload($session, $messages, $input);
        $this->validatePayload($payload, $client);
        $this->store->appendUserMessage($session, $input->newUserContent);

        $builtPayload = $this->payloadBuilder->build($payload, $client);

        yield from $this->streamAndPersist($session, $client, $builtPayload);
    }

    public function delete(string $publicId): void
    {
        $this->store->softDelete($this->resolveSession($publicId));
    }

    /** @return array{Session, Client, string[]} */
    private function prepareSendContext(string $publicId): array
    {
        $session = $this->resolveActiveSession($publicId);
        $client = Client::findOrFail($session->client_id);
        $featuresUsed = $this->extractFeaturesFromTools($session->tools ?? []);

        $authResult = $this->authorization->authorize($client, $session->model_alias, $featuresUsed);
        if (! $authResult->allowed) {
            throw new RuntimeException($authResult->message ?? 'Authorization denied', 403);
        }

        return [$session, $client, $featuresUsed];
    }

    private function resolveSession(string $publicId): Session
    {
        return $this->store->findByPublicId($publicId)
            ?? throw new SessionNotFoundException($publicId);
    }

    private function resolveActiveSession(string $publicId): Session
    {
        /** @var Session|null $session */
        $session = Session::withTrashed()->where('session_id', $publicId)->first();

        if ($session === null || $session->trashed()) {
            throw new SessionNotFoundException($publicId);
        }

        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            throw new SessionExpiredException($publicId);
        }

        return $session;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function buildPayload(Session $session, array $messages, SessionSendMessageInput $input): array
    {
        $payload = [
            'model' => $session->model_alias,
            'messages' => $messages,
        ];

        if ($session->system !== null) {
            $payload['system'] = $session->system;
        }

        if (! empty($session->tools)) {
            $payload['tools'] = $session->tools;
        }

        $decryptedMcpServers = $this->store->decryptMcpTokens($session->mcp_servers);
        if (! empty($decryptedMcpServers)) {
            $payload['mcp_servers'] = $decryptedMcpServers;
        }

        if (! empty($session->context_management)) {
            $payload['context_management'] = $session->context_management;
        }

        if ($input->maxTokens !== null) {
            $payload['max_tokens'] = $input->maxTokens;
        }

        if ($input->perRequestOverrides !== null) {
            $payload = array_merge($payload, $input->perRequestOverrides);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(array $payload, Client $client): void
    {
        $validationResult = $this->validator->validate($payload, ValidationContext::Session, $client);

        if (! $validationResult->isValid()) {
            $errors = array_map(
                fn ($e) => ['path' => $e->path, 'code' => $e->code, 'message' => $e->message],
                $validationResult->errors,
            );
            throw new RuntimeException(json_encode(['errors' => $errors]), 422);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $featuresUsed
     */
    private function callClaudeSync(array $payload, Client $client, array $featuresUsed): MessageResponse
    {
        $builtPayload = $this->payloadBuilder->build($payload, $client);
        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');
        $tokenEstimate = $this->costEstimator->estimateTokens($payload, $modelAlias);

        $sendInput = new SendMessageInput(
            payload: $builtPayload,
            client: $client,
            gatewayRequestId: 'sess_sync_'.bin2hex(random_bytes(8)),
            featuresUsed: $featuresUsed,
            estimatedInputTokens: $tokenEstimate->inputTokens,
            estimatedOutputTokens: $tokenEstimate->outputTokens,
            expectedCacheReadTokens: $tokenEstimate->cacheReadTokens,
        );

        $output = $this->claude->sendMessage($sendInput);

        if (! $output->isSuccess) {
            throw new RuntimeException(
                $output->errorMessage ?? 'Claude API error',
                $output->envelope->httpStatusCode,
            );
        }

        return $this->responseParser->parseMessageResponse(
            json_decode($output->envelope->rawBody, true, 512, JSON_THROW_ON_ERROR),
            [],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function handleCompaction(Session $session, array $content): void
    {
        if ($this->hasCompaction($content)) {
            $this->store->markCompacted($session);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function hasCompaction(array $content): bool
    {
        return array_any($content, fn (array $block): bool => ($block['type'] ?? '') === 'compaction');
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function hasMemoryToolUse(array $content): bool
    {
        return array_any(
            $content,
            fn (array $block): bool => ($block['type'] ?? '') === 'tool_use'
                && ($block['name'] ?? '') === ToolTypeCatalog::MEMORY,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @return list<MemoryCommandResult>
     */
    private function dispatchMemoryTools(Session $session, array $content): array
    {
        $toolResults = [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === ToolTypeCatalog::MEMORY) {
                $toolResults[] = $this->memoryHandler->execute($session, $block);
            }
        }

        return $toolResults;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return list<string>
     */
    private function extractFeaturesFromTools(array $tools): array
    {
        return array_values(array_unique(
            array_filter(array_column($tools, 'type')),
        ));
    }

    private function streamAndPersist(Session $session, Client $client, BuiltPayload $builtPayload): Generator
    {
        $contentBuffer = [];
        $stopReason = 'incomplete';
        $usage = [];
        $model = null;
        $messageStarted = false;
        $currentBlock = null;
        $currentBlockText = '';
        $currentEventType = null;

        $workspace = $this->workspaceResolver->resolveForClient($client);

        $response = Http::withHeaders([
            'x-api-key' => $workspace->apiKey,
            'anthropic-version' => config('llm.claude.anthropic_version'),
            'anthropic-beta' => implode(',', $builtPayload->betaHeaders),
            'content-type' => 'application/json',
        ])
            ->withBody($builtPayload->jsonBody)
            ->timeout(config('llm.claude.timeouts.request'))
            ->withOptions(['stream' => true])
            ->post(config('llm.claude.endpoints.messages'));

        $stream = $response->toPsrResponse()->getBody();

        try {
            $buffer = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    continue;
                }

                $buffer .= $chunk;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }

                    if (str_starts_with($line, 'event: ')) {
                        $currentEventType = substr($line, 7);

                        continue;
                    }

                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $dataJson = substr($line, 6);
                    $data = json_decode($dataJson, true);

                    if ($data === null) {
                        continue;
                    }

                    $eventType = $data['type'] ?? $currentEventType;

                    match ($eventType) {
                        'message_start' => (function () use ($data, &$messageStarted, &$model, &$usage) {
                            $messageStarted = true;
                            $model = $data['message']['model'] ?? null;
                            $usage = $data['message']['usage'] ?? [];
                        })(),
                        'content_block_start' => (function () use ($data, &$currentBlock, &$currentBlockText) {
                            $currentBlock = $data['content_block'] ?? null;
                            $currentBlockText = '';
                        })(),
                        'content_block_delta' => (function () use ($data, &$currentBlockText) {
                            $delta = $data['delta'] ?? [];
                            $currentBlockText .= $delta['text'] ?? $delta['thinking'] ?? '';
                        })(),
                        'content_block_stop' => (function () use (&$currentBlock, &$currentBlockText, &$contentBuffer) {
                            if ($currentBlock !== null) {
                                $block = $currentBlock;
                                $type = $block['type'] ?? '';
                                if ($type === 'text') {
                                    $block['text'] = $currentBlockText;
                                } elseif ($type === 'thinking') {
                                    $block['thinking'] = $currentBlockText;
                                }
                                $contentBuffer[] = $block;
                            }
                            $currentBlock = null;
                            $currentBlockText = '';
                        })(),
                        'message_delta' => (function () use ($data, &$stopReason, &$usage) {
                            $stopReason = $data['delta']['stop_reason'] ?? $stopReason;
                            $usage = array_merge($usage, $data['usage'] ?? []);
                        })(),
                        'message_stop' => (function () use (&$stopReason) {
                            if ($stopReason === 'incomplete') {
                                $stopReason = 'end_turn';
                            }
                        })(),
                        'compaction_delta' => (function () use ($data, &$contentBuffer) {
                            $contentBuffer[] = ['type' => 'compaction', ...$data];
                        })(),
                        default => null,
                    };

                    yield "event: $eventType\ndata: $dataJson\n\n";
                }
            }
        } finally {
            if ($messageStarted && $contentBuffer !== []) {
                $this->store->appendAssistantMessage($session, $contentBuffer, $stopReason, $usage, $model ?? '');
                $this->handleCompaction($session, $contentBuffer);

                if ($this->hasMemoryToolUse($contentBuffer)) {
                    $toolResults = $this->dispatchMemoryTools($session, $contentBuffer);
                    $this->store->appendUserMessage($session, $toolResults);
                }
            }
        }
    }
}
