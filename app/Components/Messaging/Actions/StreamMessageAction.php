<?php

declare(strict_types=1);

namespace App\Components\Messaging\Actions;

use App\Components\Claude\Claude;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Logging\DTO\LoggingRecord;
use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Components\Messaging\DTO\MessageRequestInput;
use App\Components\Messaging\DTO\PreparedMessageContext;
use App\Components\Messaging\Exceptions\MessageProcessingException;
use App\Components\Messaging\MessageProcessingCommon;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Components\Validation\ValidationContext;
use App\Http\Responders\ErrorResponder;
use DateTimeImmutable;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class StreamMessageAction
{
    public function __construct(
        private MessageProcessingCommon $common,
        private Claude $claude,
        private ErrorResponder $errorResponder,
        private Logging $logging,
    ) {}

    public function execute(MessageRequestInput $input): Response|StreamedResponse
    {
        try {
            $this->common->validate($input, ValidationContext::Sync);
        } catch (MessageProcessingException $e) {
            return $this->handleProcessingFailure($input, $e);
        }

        $context = $this->common->prepareForClaude($input);

        try {
            return $this->claude->streamMessage(
                input: $this->buildSendInput($context),
                client: $context->client,
                gatewayRequestId: $context->gatewayRequestId,
                modelAlias: $context->modelAlias,
                modelSnapshot: $context->modelSnapshot,
                features: $context->featuresUsed,
            );
        } catch (RateLimitExceededException $e) {
            return $this->handleRateLimit($context, $e);
        }
    }

    private function buildSendInput(PreparedMessageContext $context): SendMessageInput
    {
        return new SendMessageInput(
            payload: $context->builtPayload,
            client: $context->client,
            gatewayRequestId: $context->gatewayRequestId,
            featuresUsed: $context->featuresUsed,
            estimatedInputTokens: $context->tokenEstimate->inputTokens,
            estimatedOutputTokens: $context->tokenEstimate->outputTokens,
            expectedCacheReadTokens: $context->tokenEstimate->cacheReadTokens,
        );
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

    private function handleRateLimit(PreparedMessageContext $context, RateLimitExceededException $e): Response
    {
        $message = 'Rate limit pre-emptively exceeded on axis: '.$e->axis;

        $this->recordFailure(
            $context->client->id,
            $context->gatewayRequestId,
            429,
            'rate_limit_error',
            $message,
            RequestStatus::FailedClientError,
            $context->rawPayload,
            $context->modelAlias,
            $context->modelSnapshot,
        );

        return $this->errorResponder->rateLimit($message, $e->retryAfterSeconds, $context->gatewayRequestId);
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
            mode: Mode::SyncStream,
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
