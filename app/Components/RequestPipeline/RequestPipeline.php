<?php

namespace App\Components\RequestPipeline;

use App\Components\Auth\DTO\AuthenticatedClient;
use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Jobs\ProcessLlmRequest;
use App\Jobs\ProcessLlmStreamRequest;
use App\Models\PendingPrompt;
use App\Models\RequestLog;

class RequestPipeline
{
    public function __construct(
        private readonly XmlParser $xmlParser,
        private readonly RequestValidator $validator,
        private readonly SessionTracker $sessionTracker,
        private readonly IdempotencyGuard $idempotencyGuard,
    ) {}

    public function accept(
        string $xmlBody,
        AuthenticatedClient $client,
        ?string $idempotencyKey,
        ?string $clientRequestId,
        ?string $ipAddress,
    ): array {
        // 1. Idempotency check (cache first, then DB)
        if ($idempotencyKey !== null) {
            $cached = $this->idempotencyGuard->check($idempotencyKey, $client->id);
            if ($cached !== null) {
                return $cached;
            }

            $existing = RequestLog::where('api_client_id', $client->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('created_at', '>=', now()->subDay())
                ->first();

            if ($existing) {
                $result = [
                    'status' => 'accepted',
                    'request_id' => $existing->request_id,
                    'meta' => $existing->meta_data ?? [],
                    'provider' => [
                        'name' => $existing->provider_requested ?? 'auto',
                        'model' => $existing->model_requested ?? 'auto',
                    ],
                    'callback_url' => $existing->callback_url,
                ];
                $this->idempotencyGuard->store($idempotencyKey, $client->id, $result);
                return $result;
            }
        }

        // 2. Parse XML
        $parsed = $this->xmlParser->parse($xmlBody);

        // 3. Validate
        $this->validator->validate($parsed, $client);

        // 4. Determine provider/model
        $providerName = $parsed->provider?->name;
        $modelName = $parsed->provider?->model;

        // 5. Detect media/tools
        $hasMedia = collect($parsed->blocks)->contains(fn ($b) => in_array($b->type, ['image', 'document', 'audio'], true));
        $hasTools = $parsed->tools !== null && !empty($parsed->tools->tools);
        $stream = $parsed->parameters?->stream ?? false;

        // 6. Save to request_log
        $requestLog = RequestLog::create([
            'request_id' => $parsed->meta->requestId,
            'api_client_id' => $client->id,
            'session_id' => $parsed->meta->sessionId,
            'step_id' => $parsed->meta->stepId,
            'provider_requested' => $providerName,
            'model_requested' => $modelName,
            'priority' => $parsed->meta->priority ?? 'normal',
            'status' => RequestStatus::Accepted,
            'callback_url' => $parsed->callback->url,
            'meta_data' => $parsed->meta->toArray(),
            'has_tools' => $hasTools,
            'has_media' => $hasMedia,
            'stream' => $stream,
            'idempotency_key' => $idempotencyKey,
            'ip_address' => $ipAddress,
        ]);

        // 7. Save to pending_prompts
        PendingPrompt::create([
            'request_log_id' => $requestLog->id,
            'prompt_xml' => $parsed->rawPromptXml,
            'tools_xml' => $parsed->rawToolsXml,
            'parameters_xml' => $parsed->rawParametersXml,
            'provider_xml' => $parsed->rawProviderXml,
            'expires_at' => now()->addDays(3),
        ]);

        // 8. Validate and register session step atomically (with Redis lock)
        if ($parsed->meta->sessionId) {
            $this->sessionTracker->validateAndRegister(
                $parsed->meta->sessionId,
                $parsed->meta->stepId,
                $client->id,
                $requestLog->id,
            );
        }

        // 9. Dispatch job (streaming or regular)
        $queueName = config('llm.queues.' . ($parsed->meta->priority ?? 'normal'), 'default');
        if ($stream) {
            ProcessLlmStreamRequest::dispatch($requestLog->id)->onQueue($queueName);
        } else {
            ProcessLlmRequest::dispatch($requestLog->id)->onQueue($queueName);
        }

        // 10. Return synchronous response
        $result = [
            'status' => 'accepted',
            'request_id' => $parsed->meta->requestId,
            'meta' => $parsed->meta->toArray(),
            'provider' => [
                'name' => $providerName ?? 'auto',
                'model' => $modelName ?? 'auto',
            ],
            'callback_url' => $parsed->callback->url,
            'dev_mode' => $client->devMode,
        ];

        // 11. Store idempotency cache
        if ($idempotencyKey !== null) {
            $this->idempotencyGuard->store($idempotencyKey, $client->id, $result);
        }

        return $result;
    }
}
