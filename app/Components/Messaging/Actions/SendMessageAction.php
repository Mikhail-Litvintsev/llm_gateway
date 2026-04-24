<?php

declare(strict_types=1);

namespace App\Components\Messaging\Actions;

use App\Components\Billing\Billing;
use App\Components\Billing\DTO\SpendRecordResult;
use App\Components\Claude\Contracts\MessageSender;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\DTO\SendMessageOutput;
use App\Components\Delivery\Sync\DTO\GatewayHeaders;
use App\Components\Delivery\Sync\SyncResponder;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Response;

final readonly class SendMessageAction
{
    public function __construct(
        private MessageProcessingCommon $common,
        private MessageSender $claude,
        private Billing $billing,
        private Logging $logging,
        private SyncResponder $responder,
        private ErrorResponder $errorResponder,
    ) {}

    public function execute(MessageRequestInput $input): Response
    {
        try {
            $this->common->validate($input, ValidationContext::Sync);
        } catch (MessageProcessingException $e) {
            return $this->handleProcessingFailure($input, $e);
        }

        $context = $this->common->prepareForClaude($input);

        try {
            $output = $this->claude->sendMessage($this->buildSendInput($context));
        } catch (RateLimitExceededException $e) {
            return $this->handleRateLimit($context, $e);
        } catch (ConnectionException) {
            return $this->handleConnectionFailure($context);
        }

        $spendResult = $output->isSuccess
            ? $this->billing->recordSpend($context->client, $output->costUsd)
            : null;

        $this->logging->record($this->buildLoggingRecord($context, $output));

        return $this->responder->respond($output->envelope, $this->buildHeaders($context, $output, $spendResult));
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

    private function buildLoggingRecord(PreparedMessageContext $context, SendMessageOutput $output): LoggingRecord
    {
        $status = $output->isSuccess
            ? RequestStatus::Completed
            : ($output->envelope->httpStatusCode >= 500
                ? RequestStatus::FailedServerError
                : RequestStatus::FailedClientError);

        return new LoggingRecord(
            requestId: $context->gatewayRequestId,
            clientId: $context->client->id,
            endpoint: Endpoint::Messages,
            mode: Mode::Sync,
            modelAlias: $context->modelAlias,
            modelSnapshot: $context->modelSnapshot,
            anthropicRequestId: $output->anthropicRequestId,
            anthropicOrganizationId: $output->envelope->anthropicHeaders['anthropic-organization-id'] ?? null,
            status: $status,
            httpStatus: $output->envelope->httpStatusCode,
            errorType: $output->errorType,
            errorMessage: $output->errorMessage,
            serviceTierUsed: $output->serviceTierUsed,
            createdAt: $context->startedAt,
            startedAt: $context->startedAt,
            completedAt: new DateTimeImmutable,
            inputTokens: $output->usage['input_tokens'] ?? 0,
            outputTokens: $output->usage['output_tokens'] ?? 0,
            cacheReadTokens: $output->usage['cache_read_input_tokens'] ?? 0,
            thinkingTokens: $output->usage['thinking_tokens'] ?? 0,
            costUsd: number_format($output->costUsd, 8, '.', ''),
            costBreakdown: $output->costBreakdown,
            requestPayload: $context->builtPayload->jsonBody,
            responsePayload: $output->envelope->rawBody,
            retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
        );
    }

    private function buildHeaders(
        PreparedMessageContext $context,
        SendMessageOutput $output,
        ?SpendRecordResult $spendResult,
    ): GatewayHeaders {
        return new GatewayHeaders(
            gatewayRequestId: $context->gatewayRequestId,
            anthropicRequestId: $output->anthropicRequestId,
            modelAlias: $context->modelAlias,
            modelSnapshot: $context->modelSnapshot,
            costUsd: $output->costUsd,
            costBreakdown: $output->costBreakdown,
            spendRemainingUsd: $spendResult?->remainingUsd,
            serviceTierUsed: $output->serviceTierUsed,
            cacheHitTokens: $output->cacheHitTokens,
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

    private function handleConnectionFailure(PreparedMessageContext $context): Response
    {
        $message = 'Failed to connect to upstream provider.';

        $this->logging->record(new LoggingRecord(
            requestId: $context->gatewayRequestId,
            clientId: $context->client->id,
            endpoint: Endpoint::Messages,
            mode: Mode::Sync,
            modelAlias: $context->modelAlias,
            modelSnapshot: $context->modelSnapshot,
            anthropicRequestId: null,
            anthropicOrganizationId: null,
            status: RequestStatus::FailedServerError,
            httpStatus: 504,
            errorType: 'upstream_timeout',
            errorMessage: $message,
            serviceTierUsed: null,
            createdAt: $context->startedAt,
            startedAt: $context->startedAt,
            completedAt: new DateTimeImmutable,
            requestPayload: $context->builtPayload->jsonBody,
            retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
        ));

        return $this->errorResponder->upstreamTimeout($message, $context->gatewayRequestId);
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
            requestPayload: json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
        ));
    }
}
