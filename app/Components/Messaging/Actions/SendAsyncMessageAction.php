<?php

declare(strict_types=1);

namespace App\Components\Messaging\Actions;

use App\Components\Billing\CostEstimator;
use App\Components\Logging\DTO\LoggingRecord;
use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Components\Messaging\DTO\MessageRequestInput;
use App\Components\Messaging\Exceptions\MessageProcessingException;
use App\Components\Messaging\MessageProcessingCommon;
use App\Components\Validation\ValidationContext;
use App\Http\Responders\ErrorResponder;
use App\Jobs\ProcessAsyncMessage;
use App\Repositories\AsyncPendingRepository;
use App\Repositories\CallbackUrlRepository;
use App\Repositories\RequestRepository;
use DateTimeImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final readonly class SendAsyncMessageAction
{
    public function __construct(
        private MessageProcessingCommon $common,
        private CostEstimator $costEstimator,
        private CallbackUrlRepository $callbackUrls,
        private RequestRepository $requests,
        private AsyncPendingRepository $pending,
        private ErrorResponder $errorResponder,
        private Logging $logging,
    ) {}

    public function execute(MessageRequestInput $input): Response
    {
        try {
            $this->common->validatePayload($input, ValidationContext::AsyncCallback);
            $this->assertCallbackWhitelisted($input);
            $this->common->authorizeAndPreCheck($input);
        } catch (MessageProcessingException $e) {
            return $this->handleProcessingFailure($input, $e);
        }

        [$modelAlias, $modelSnapshot] = $this->common->resolveModelInfo($input);
        $estimatedCost = $this->costEstimator->estimate($input->payload, $modelAlias);

        $expiresAt = $this->computeExpiresAt();
        $callbackUrl = (string) $input->payload['callback_url'];

        $this->persistAccepted($input, $modelAlias, $modelSnapshot, $callbackUrl, $expiresAt);
        $this->dispatchJob($input);

        return $this->buildAcceptedResponse(
            $input->gatewayRequestId,
            $estimatedCost,
            $callbackUrl,
            $expiresAt,
        );
    }

    private function assertCallbackWhitelisted(MessageRequestInput $input): void
    {
        $callbackUrl = $input->payload['callback_url'] ?? null;

        if (! is_string($callbackUrl) || $callbackUrl === '') {
            throw MessageProcessingException::validationFailed(
                'callback_url is required',
                $input->gatewayRequestId,
            );
        }

        if (! $this->callbackUrls->isWhitelisted($input->client->id, $callbackUrl)) {
            throw MessageProcessingException::validationFailed(
                'callback_url is not whitelisted for this client',
                $input->gatewayRequestId,
            );
        }
    }

    private function computeExpiresAt(): DateTimeImmutable
    {
        $ttlDays = (int) config('llm.async.pending_ttl_days', 3);

        return new DateTimeImmutable('+'.$ttlDays.' days');
    }

    private function persistAccepted(
        MessageRequestInput $input,
        string $modelAlias,
        string $modelSnapshot,
        string $callbackUrl,
        DateTimeImmutable $expiresAt,
    ): void {
        $payloadJson = json_encode($input->payload, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($input, $modelAlias, $modelSnapshot, $payloadJson, $callbackUrl, $expiresAt): void {
            $this->requests->createAccepted(
                $input->gatewayRequestId,
                $input->client->id,
                Endpoint::Messages->value,
                Mode::AsyncCallback->value,
                $modelAlias,
                $modelSnapshot,
                RequestStatus::Accepted->value,
            );

            $this->pending->create(
                $input->gatewayRequestId,
                $callbackUrl,
                $payloadJson,
                $expiresAt,
            );
        });
    }

    private function dispatchJob(MessageRequestInput $input): void
    {
        $queue = ($input->payload['priority'] ?? 'default') === 'high' ? 'high' : 'default';

        ProcessAsyncMessage::dispatch($input->gatewayRequestId)->onQueue($queue);
    }

    private function buildAcceptedResponse(
        string $gatewayRequestId,
        float $estimatedCost,
        string $callbackUrl,
        DateTimeImmutable $expiresAt,
    ): Response {
        $body = json_encode([
            'request_id' => $gatewayRequestId,
            'status' => 'accepted',
            'estimated_cost_usd' => $estimatedCost,
            'estimate_mode' => 'character_based',
            'callback_url' => $callbackUrl,
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($body, 202, [
            'Content-Type' => 'application/json',
            'X-Gateway-Request-Id' => $gatewayRequestId,
        ]);
    }

    private function handleProcessingFailure(MessageRequestInput $input, MessageProcessingException $e): Response
    {
        return match ($e->kind) {
            MessageProcessingException::KIND_VALIDATION => $this->failValidation($input, $e),
            MessageProcessingException::KIND_AUTHORIZATION => $this->failAuthorization($input, $e),
            MessageProcessingException::KIND_BILLING => $this->failBilling($input, $e),
            default => $this->errorResponder->upstreamError($e->getMessage(), 500, $e->gatewayRequestId),
        };
    }

    private function failValidation(MessageRequestInput $input, MessageProcessingException $e): Response
    {
        $this->recordFailure(
            $input->client->id,
            $e->gatewayRequestId,
            400,
            'invalid_request_error',
            $e->getMessage(),
            RequestStatus::FailedValidation,
            $input->payload,
            $e->modelAlias,
            $e->modelSnapshot,
        );

        return $this->errorResponder->invalidRequest($e->getMessage(), $e->gatewayRequestId);
    }

    private function failAuthorization(MessageRequestInput $input, MessageProcessingException $e): Response
    {
        $result = $e->authorizationResult;
        $httpStatus = $result?->reason?->httpStatusCode() ?? 403;
        $errorType = $result?->reason?->errorType() ?? 'permission_error';

        $this->recordFailure(
            $input->client->id,
            $e->gatewayRequestId,
            $httpStatus,
            $errorType,
            $e->getMessage(),
            RequestStatus::FailedAuth,
            $input->payload,
            $e->modelAlias,
            $e->modelSnapshot,
        );

        return $this->errorResponder->authorizationError($errorType, $e->getMessage(), $httpStatus, $e->gatewayRequestId);
    }

    private function failBilling(MessageRequestInput $input, MessageProcessingException $e): Response
    {
        $this->recordFailure(
            $input->client->id,
            $e->gatewayRequestId,
            402,
            'billing_error',
            $e->getMessage(),
            RequestStatus::FailedAuth,
            $input->payload,
            $e->modelAlias,
            $e->modelSnapshot,
        );

        return $this->errorResponder->billingCapExceeded($e->getMessage(), $e->gatewayRequestId);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function recordFailure(
        int $clientId,
        string $gatewayRequestId,
        int $httpStatus,
        string $errorType,
        ?string $errorMessage,
        RequestStatus $status,
        array $rawPayload,
        string $modelAlias,
        string $modelSnapshot,
    ): void {
        $now = new DateTimeImmutable;

        $this->logging->record(new LoggingRecord(
            requestId: $gatewayRequestId,
            clientId: $clientId,
            endpoint: Endpoint::Messages,
            mode: Mode::AsyncCallback,
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
            requestPayload: json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
        ));
    }
}
