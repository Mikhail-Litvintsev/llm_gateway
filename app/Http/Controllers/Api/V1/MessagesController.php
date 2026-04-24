<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Authorization\Authorization;
use App\Components\Authorization\DTO\AuthorizationResult;
use App\Components\Billing\Billing;
use App\Components\Billing\CostEstimator;
use App\Components\Caching\Caching;
use App\Components\Claude\Claude;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Delivery\Sync\DTO\GatewayHeaders;
use App\Components\Delivery\Sync\SyncResponder;
use App\Components\Logging\DTO\LoggingRecord;
use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Components\Routing\ModelResolver;
use App\Components\Validation\DTO\ValidationResult;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAsyncMessage;
use App\Models\Client;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MessagesController extends Controller
{
    public function __construct(
        private readonly MessageRequestValidator $validator,
        private readonly ModelResolver $models,
        private readonly Authorization $authorization,
        private readonly Caching $caching,
        private readonly Billing $billing,
        private readonly Logging $logging,
        private readonly Claude $claude,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly SyncResponder $responder,
        private readonly CostEstimator $costEstimator,
    ) {}

    /**
     * Orchestration: auth (middleware) → validate → model resolve → authorize →
     * billing pre-check → cache inject → build payload → Anthropic call →
     * billing record → logging → sync response.
     */
    public function send(Request $request): Response|StreamedResponse
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $gatewayRequestId = 'req_'.Str::random(24);
        $payload = $request->json()->all();
        $startedAt = new DateTimeImmutable;

        $validation = $this->validator->validate($payload, ValidationContext::Sync, $client);
        if (! $validation->isValid()) {
            return $this->failValidation($client, $gatewayRequestId, $validation, $payload);
        }

        if (($payload['stream'] ?? false) === true) {
            return $this->streamDelegate($client, $gatewayRequestId, $payload);
        }

        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');
        $resolved = $this->models->resolve($modelAlias);
        $features = $this->extractFeatures($payload);

        $authResult = $this->authorization->authorize($client, $modelAlias, $features);
        if (! $authResult->allowed) {
            return $this->failAuthorization($client, $gatewayRequestId, $authResult, $modelAlias, $resolved->snapshot, $payload);
        }

        $preCheck = $this->billing->preCheck($client);
        if (! $preCheck->decision->isAllowed()) {
            return $this->failBilling($client, $gatewayRequestId, $modelAlias, $resolved->snapshot, $payload);
        }

        $injection = $this->caching->autoInject($payload, $modelAlias, $client);
        $payload = $injection->payload;

        $builtPayload = $this->payloadBuilder->build($payload, $client);
        $tokenEstimate = $this->costEstimator->estimateTokens($payload, $modelAlias);

        try {
            $output = $this->claude->sendMessage(new SendMessageInput(
                payload: $builtPayload,
                client: $client,
                gatewayRequestId: $gatewayRequestId,
                featuresUsed: $features,
                estimatedInputTokens: $tokenEstimate->inputTokens,
                estimatedOutputTokens: $tokenEstimate->outputTokens,
                expectedCacheReadTokens: $tokenEstimate->cacheReadTokens,
            ));
        } catch (RateLimitExceededException $e) {
            return $this->failRateLimit($client, $gatewayRequestId, $modelAlias, $resolved->snapshot, $payload, $e);
        } catch (ConnectionException) {
            return $this->failConnection($client, $gatewayRequestId, $modelAlias, $resolved->snapshot, $startedAt, $builtPayload->jsonBody);
        }

        $completedAt = new DateTimeImmutable;
        $spendResult = $output->isSuccess
            ? $this->billing->recordSpend($client, $output->costUsd)
            : null;

        $status = $output->isSuccess
            ? RequestStatus::Completed
            : ($output->envelope->httpStatusCode >= 500
                ? RequestStatus::FailedServerError
                : RequestStatus::FailedClientError);

        $this->logging->record(new LoggingRecord(
            requestId: $gatewayRequestId,
            clientId: $client->id,
            endpoint: Endpoint::Messages,
            mode: Mode::Sync,
            modelAlias: $modelAlias,
            modelSnapshot: $resolved->snapshot,
            anthropicRequestId: $output->anthropicRequestId,
            anthropicOrganizationId: $output->envelope->anthropicHeaders['anthropic-organization-id'] ?? null,
            status: $status,
            httpStatus: $output->envelope->httpStatusCode,
            errorType: $output->errorType,
            errorMessage: $output->errorMessage,
            serviceTierUsed: $output->serviceTierUsed,
            createdAt: $startedAt,
            startedAt: $startedAt,
            completedAt: $completedAt,
            inputTokens: $output->usage['input_tokens'] ?? 0,
            outputTokens: $output->usage['output_tokens'] ?? 0,
            cacheReadTokens: $output->usage['cache_read_input_tokens'] ?? 0,
            thinkingTokens: $output->usage['thinking_tokens'] ?? 0,
            costUsd: number_format($output->costUsd, 8, '.', ''),
            costBreakdown: $output->costBreakdown,
            requestPayload: $builtPayload->jsonBody,
            responsePayload: $output->envelope->rawBody,
            retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
        ));

        $headers = new GatewayHeaders(
            gatewayRequestId: $gatewayRequestId,
            anthropicRequestId: $output->anthropicRequestId,
            modelAlias: $modelAlias,
            modelSnapshot: $resolved->snapshot,
            costUsd: $output->costUsd,
            costBreakdown: $output->costBreakdown,
            spendRemainingUsd: $spendResult?->remainingUsd,
            serviceTierUsed: $output->serviceTierUsed,
            cacheHitTokens: $output->cacheHitTokens,
        );

        return $this->responder->respond($output->envelope, $headers);
    }

    public function async(Request $request): JsonResponse
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $gatewayRequestId = 'req_'.Str::random(24);
        $payload = $request->json()->all();

        $validation = $this->validator->validate($payload, ValidationContext::AsyncCallback, $client);
        if (! $validation->isValid()) {
            return new JsonResponse([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => $validation->errors[0]->message ?? 'Validation failed'],
            ], 400, ['X-Gateway-Request-Id' => $gatewayRequestId]);
        }

        $callbackUrl = $payload['callback_url'] ?? null;
        $this->validateCallbackWhitelist($client, $callbackUrl);

        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');
        $resolved = $this->models->resolve($modelAlias);
        $features = $this->extractFeatures($payload);
        $features[] = 'webhook';

        $authResult = $this->authorization->authorize($client, $modelAlias, $features);
        if (! $authResult->allowed) {
            $httpStatus = $authResult->reason->httpStatusCode();

            return new JsonResponse([
                'type' => 'error',
                'error' => ['type' => $authResult->reason->errorType(), 'message' => $authResult->message],
            ], $httpStatus, ['X-Gateway-Request-Id' => $gatewayRequestId]);
        }

        $preCheck = $this->billing->preCheck($client);
        if (! $preCheck->decision->isAllowed()) {
            return new JsonResponse([
                'type' => 'error',
                'error' => ['type' => 'billing_error', 'message' => 'Monthly spend cap exceeded.'],
            ], 402, ['X-Gateway-Request-Id' => $gatewayRequestId]);
        }

        $estimated = $this->costEstimator->estimate($payload, $modelAlias);

        $ttlDays = (int) config('llm.async.pending_ttl_days', 3);
        $expiresAt = now()->addDays($ttlDays);

        DB::transaction(function () use ($gatewayRequestId, $client, $modelAlias, $resolved, $payload, $callbackUrl, $expiresAt): void {
            DB::table('requests')->insert([
                'request_id' => $gatewayRequestId,
                'client_id' => $client->id,
                'endpoint' => Endpoint::Messages->value,
                'mode' => Mode::AsyncCallback->value,
                'model_alias' => $modelAlias,
                'model_snapshot' => $resolved->snapshot,
                'status' => RequestStatus::Accepted->value,
                'created_at' => now(),
            ]);

            DB::table('async_pending')->insert([
                'request_id' => $gatewayRequestId,
                'payload_for_anthropic' => json_encode($payload, JSON_THROW_ON_ERROR),
                'callback_url' => $callbackUrl,
                'status' => 'queued',
                'callback_attempts' => 0,
                'next_attempt_at' => null,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $queue = ($payload['priority'] ?? 'default') === 'high' ? 'high' : 'default';
        ProcessAsyncMessage::dispatch($gatewayRequestId)->onQueue($queue);

        return response()->json([
            'request_id' => $gatewayRequestId,
            'status' => 'accepted',
            'estimated_cost_usd' => $estimated,
            'estimate_mode' => 'character_based',
            'callback_url' => $callbackUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 202)->header('X-Gateway-Request-Id', $gatewayRequestId);
    }

    /**
     * Pass-through to Anthropic's /v1/messages/count_tokens.
     * No billing, no logging, no spend mutation.
     */
    public function countTokens(Request $request): Response
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $gatewayRequestId = 'req_'.Str::random(24);
        $payload = $request->json()->all();

        $validation = $this->validator->validate($payload, ValidationContext::CountTokens, $client);
        if (! $validation->isValid()) {
            return $this->failValidation($client, $gatewayRequestId, $validation, $payload);
        }

        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');
        $this->models->resolve($modelAlias);

        $authResult = $this->authorization->authorize($client, $modelAlias, []);
        if (! $authResult->allowed) {
            return $this->failAuthorization($client, $gatewayRequestId, $authResult, $modelAlias, '', $payload);
        }

        $builtPayload = $this->payloadBuilder->build($payload, $client);

        try {
            $envelope = $this->claude->countTokens($builtPayload, $client);
        } catch (RateLimitExceededException $e) {
            $response = $this->errorResponse(
                429,
                'rate_limit_error',
                'Rate limit pre-emptively exceeded on axis: '.$e->axis,
                $gatewayRequestId,
            );
            $response->headers->set('Retry-After', (string) $e->retryAfterSeconds);

            return $response;
        }

        $inputTokens = json_decode($envelope->rawBody, true)['input_tokens'] ?? 0;
        $maxTokens = (int) ($payload['max_tokens'] ?? 1024);
        $outputFactor = (float) config('llm.claude.count_tokens.output_tokens_factor', 0.5);
        $assumedOutput = (int) ceil($maxTokens * $outputFactor);

        $estimatedCost = $this->costEstimator->estimateFromTokens($inputTokens, $assumedOutput, $modelAlias);

        $response = new Response($envelope->rawBody, $envelope->httpStatusCode);
        foreach ($envelope->anthropicHeaders as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('X-Gateway-Request-Id', $gatewayRequestId);
        $response->headers->set('X-Gateway-Estimated-Cost-USD', number_format($estimatedCost, 6, '.', ''));

        return $response;
    }

    public function show(Request $request, string $requestId): JsonResponse
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $row = DB::table('requests')->where('request_id', $requestId)->first();
        if (! $row || $row->client_id !== $client->id) {
            return new JsonResponse([
                'type' => 'error',
                'error' => ['type' => 'not_found_error', 'message' => 'request not found'],
            ], 404);
        }

        $usage = DB::table('request_usage')->where('request_id', $row->request_id)->first();

        $anthropicResponse = null;
        $includeResponse = $request->query('include_response', 'true') !== 'false';
        if ($includeResponse) {
            $raw = DB::table('request_raw')->where('request_id', $row->request_id)->first();
            if ($raw?->response_payload) {
                $anthropicResponse = json_decode($raw->response_payload, true);
            }
        }

        $billing = $usage ? [
            'cost_usd' => (float) $usage->cost_usd,
            'cost_breakdown' => json_decode($usage->cost_breakdown ?? 'null', true),
            'monthly_spend_after_usd' => null,
        ] : null;

        $error = $row->error_type ? [
            'type' => $row->error_type,
            'message' => $row->error_message,
        ] : null;

        $latencyMs = ($row->started_at && $row->completed_at)
            ? (int) ((strtotime($row->completed_at) - strtotime($row->started_at)) * 1000)
            : null;

        return new JsonResponse([
            'request_id' => $row->request_id,
            'status' => $row->status,
            'model_alias' => $row->model_alias,
            'model_snapshot' => $row->model_snapshot,
            'endpoint' => $row->endpoint,
            'mode' => $row->mode,
            'created_at' => $row->created_at,
            'completed_at' => $row->completed_at,
            'latency_ms' => $latencyMs,
            'anthropic_request_id' => $row->anthropic_request_id,
            'anthropic_response' => $anthropicResponse,
            'billing' => $billing,
            'error' => $error,
        ], 200, ['X-Gateway-Request-Id' => $row->request_id]);
    }

    private function validateCallbackWhitelist(Client $client, ?string $callbackUrl): void
    {
        if ($callbackUrl === null) {
            abort(400, json_encode([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'callback_url is required'],
            ]));
        }

        $exists = DB::table('client_callback_urls')
            ->where('client_id', $client->id)
            ->where('url', $callbackUrl)
            ->exists();

        if (! $exists) {
            abort(response()->json([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'callback_url is not whitelisted for this client'],
            ], 400));
        }
    }

    private function streamDelegate(Client $client, string $gatewayRequestId, array $payload): Response|StreamedResponse
    {
        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');
        $resolved = $this->models->resolve($modelAlias);
        $features = $this->extractFeatures($payload);

        $authResult = $this->authorization->authorize($client, $modelAlias, $features);
        if (! $authResult->allowed) {
            return $this->failAuthorization($client, $gatewayRequestId, $authResult, $modelAlias, $resolved->snapshot, $payload);
        }

        $preCheck = $this->billing->preCheck($client);
        if (! $preCheck->decision->isAllowed()) {
            return $this->failBilling($client, $gatewayRequestId, $modelAlias, $resolved->snapshot, $payload);
        }

        $injection = $this->caching->autoInject($payload, $modelAlias, $client);
        $payload = $injection->payload;

        $builtPayload = $this->payloadBuilder->build($payload, $client);
        $tokenEstimate = $this->costEstimator->estimateTokens($payload, $modelAlias);

        try {
            return $this->claude->streamMessage(
                input: new SendMessageInput(
                    payload: $builtPayload,
                    client: $client,
                    gatewayRequestId: $gatewayRequestId,
                    featuresUsed: $features,
                    estimatedInputTokens: $tokenEstimate->inputTokens,
                    estimatedOutputTokens: $tokenEstimate->outputTokens,
                    expectedCacheReadTokens: $tokenEstimate->cacheReadTokens,
                ),
                client: $client,
                gatewayRequestId: $gatewayRequestId,
                modelAlias: $modelAlias,
                modelSnapshot: $resolved->snapshot,
                features: $features,
            );
        } catch (RateLimitExceededException $e) {
            return $this->failRateLimit($client, $gatewayRequestId, $modelAlias, $resolved->snapshot, $payload, $e);
        }
    }

    private function failValidation(
        Client $client,
        string $gatewayRequestId,
        ValidationResult $validation,
        array $payload,
    ): Response {
        $message = $validation->errors[0]->message ?? 'Validation failed';

        $this->logFailure($client, $gatewayRequestId, RequestStatus::FailedValidation, 400, 'invalid_request_error', $message, $payload);

        return $this->errorResponse(400, 'invalid_request_error', $message, $gatewayRequestId);
    }

    private function failAuthorization(
        Client $client,
        string $gatewayRequestId,
        AuthorizationResult $result,
        string $modelAlias,
        string $modelSnapshot,
        array $payload,
    ): Response {
        $httpStatus = $result->reason->httpStatusCode();
        $errorType = $result->reason->errorType();

        $this->logFailure($client, $gatewayRequestId, RequestStatus::FailedAuth, $httpStatus, $errorType, $result->message, $payload, $modelAlias, $modelSnapshot);

        return $this->errorResponse($httpStatus, $errorType, $result->message, $gatewayRequestId);
    }

    private function failBilling(
        Client $client,
        string $gatewayRequestId,
        string $modelAlias,
        string $modelSnapshot,
        array $payload,
    ): Response {
        $message = 'Monthly spend cap exceeded.';

        $this->logFailure($client, $gatewayRequestId, RequestStatus::FailedAuth, 402, 'billing_error', $message, $payload, $modelAlias, $modelSnapshot);

        return $this->errorResponse(402, 'billing_error', $message, $gatewayRequestId);
    }

    private function failRateLimit(
        Client $client,
        string $gatewayRequestId,
        string $modelAlias,
        string $modelSnapshot,
        array $payload,
        RateLimitExceededException $exception,
    ): Response {
        $message = 'Rate limit pre-emptively exceeded on axis: '.$exception->axis;

        $this->logFailure(
            $client,
            $gatewayRequestId,
            RequestStatus::FailedClientError,
            429,
            'rate_limit_error',
            $message,
            $payload,
            $modelAlias,
            $modelSnapshot,
        );

        $response = $this->errorResponse(429, 'rate_limit_error', $message, $gatewayRequestId);
        $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);

        return $response;
    }

    private function failConnection(
        Client $client,
        string $gatewayRequestId,
        string $modelAlias,
        string $modelSnapshot,
        DateTimeImmutable $startedAt,
        string $requestPayload,
    ): Response {
        $message = 'Failed to connect to upstream provider.';

        $this->logging->record(new LoggingRecord(
            requestId: $gatewayRequestId,
            clientId: $client->id,
            endpoint: Endpoint::Messages,
            mode: Mode::Sync,
            modelAlias: $modelAlias,
            modelSnapshot: $modelSnapshot,
            anthropicRequestId: null,
            anthropicOrganizationId: null,
            status: RequestStatus::FailedServerError,
            httpStatus: 504,
            errorType: 'upstream_timeout',
            errorMessage: $message,
            serviceTierUsed: null,
            createdAt: $startedAt,
            startedAt: $startedAt,
            completedAt: new DateTimeImmutable,
            requestPayload: $requestPayload,
            retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
        ));

        return $this->errorResponse(504, 'upstream_timeout', $message, $gatewayRequestId);
    }

    private function logFailure(
        Client $client,
        string $gatewayRequestId,
        RequestStatus $status,
        int $httpStatus,
        string $errorType,
        ?string $errorMessage,
        array $payload,
        string $modelAlias = '',
        string $modelSnapshot = '',
    ): void {
        $now = new DateTimeImmutable;

        $this->logging->record(new LoggingRecord(
            requestId: $gatewayRequestId,
            clientId: $client->id,
            endpoint: Endpoint::Messages,
            mode: Mode::Sync,
            modelAlias: $modelAlias,
            modelSnapshot: $modelSnapshot,
            anthropicRequestId: null,
            anthropicOrganizationId: null,
            status: $status,
            httpStatus: $httpStatus,
            errorType: $errorType,
            errorMessage: $errorMessage,
            serviceTierUsed: null,
            createdAt: $now,
            startedAt: null,
            completedAt: $now,
            requestPayload: json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
        ));
    }

    private function errorResponse(int $httpStatus, string $errorType, ?string $message, string $gatewayRequestId): Response
    {
        $body = json_encode([
            'type' => 'error',
            'error' => ['type' => $errorType, 'message' => $message ?? 'Unknown error'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($body, $httpStatus, [
            'Content-Type' => 'application/json',
            'X-Gateway-Request-Id' => $gatewayRequestId,
        ]);
    }

    /**
     * @return string[]
     */
    private function extractFeatures(array $payload): array
    {
        $features = [];

        if (isset($payload['thinking'])) {
            $features[] = 'thinking';
        }

        if (isset($payload['tools'])) {
            foreach ($payload['tools'] as $tool) {
                $name = $tool['name'] ?? '';
                if (str_starts_with($name, 'web_search')) {
                    $features[] = 'web_search';
                }
                if ($name === 'code_execution') {
                    $features[] = 'code_execution';
                }
                if (str_starts_with($name, 'computer_')) {
                    $features[] = 'computer_use';
                }
                if ($name === 'bash') {
                    $features[] = 'bash';
                }
                if ($name === 'text_editor') {
                    $features[] = 'text_editor';
                }
            }
        }

        if (($payload['service_tier'] ?? null) === 'auto') {
            $features[] = 'priority_tier';
        }

        if (! empty($payload['citations']['enabled'])) {
            $features[] = 'citations';
        }

        if ($this->hasCacheControl($payload)) {
            $features[] = 'prompt_caching';
        }

        if (isset($payload['output_config'])) {
            $features[] = 'structured_outputs';
        }

        return array_unique($features);
    }

    private function hasCacheControl(array $payload): bool
    {
        if (isset($payload['cache_control'])) {
            return true;
        }

        foreach ($payload['system'] ?? [] as $block) {
            if (is_array($block) && isset($block['cache_control'])) {
                return true;
            }
        }

        foreach ($payload['messages'] ?? [] as $message) {
            $content = $message['content'] ?? [];
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && isset($block['cache_control'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
