<?php

namespace App\Components\CallbackDelivery;

use App\Components\CallbackDelivery\Contracts\CallbackSignerContract;
use App\Components\CallbackDelivery\DTO\DeliveryResult;
use App\Components\CallbackDelivery\Enums\CallbackEventType;
use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use App\Models\PendingResponse;
use Illuminate\Support\Facades\Log;

class CallbackDelivery
{
    public function __construct(
        private readonly CallbackSender $sender,
        private readonly CallbackSignerContract $signer,
        private readonly RetryCalculator $retryCalculator,
    ) {}

    public function deliver(PendingResponse $pending): DeliveryResult
    {
        $requestLog = $pending->requestLog;
        $client = $requestLog->apiClient;

        // 1. Prepare payload
        $payload = $pending->response_payload;
        $payloadJson = json_encode($payload);

        // 2. Sign
        $signatureHeaders = $this->signer->sign(
            $payloadJson,
            $client->signing_secret,
            $requestLog->meta_data['request_id'] ?? '',
        );

        // 3. Determine event type
        $eventType = ($payload['status'] ?? '') === 'error'
            ? CallbackEventType::Error->value
            : CallbackEventType::Completion->value;

        // 4. Assemble headers
        $headers = array_merge(
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-LLM-Event-Type' => $eventType,
            ],
            $signatureHeaders,
            $pending->callback_headers ?? [],
        );

        // 5. Send
        $result = $this->sender->send(
            $pending->callback_url,
            $pending->callback_method,
            $payload,
            $headers,
        );

        // 6. Update pending_response
        $pending->delivery_attempts++;
        $pending->last_attempt_at = now();

        // Обрезать ошибку до 10000 символов, чтобы уместить в TEXT-колонку
        $truncatedError = $result->error !== null ? mb_substr($result->error, 0, 10000) : null;

        if ($result->success) {
            $pending->delivery_status = DeliveryStatus::Delivered;
            $pending->save();
        } elseif ($result->httpStatus >= 400 && $result->httpStatus < 500) {
            // Client error — do not retry
            $pending->delivery_status = DeliveryStatus::Failed;
            $pending->last_error = $truncatedError;
            $pending->save();
        } elseif ($pending->delivery_attempts >= $pending->max_attempts) {
            // All attempts exhausted
            $pending->delivery_status = DeliveryStatus::Failed;
            $pending->last_error = $truncatedError;
            $pending->save();
            $requestLog->update(['error_code' => 'CALLBACK_DELIVERY_FAILED']);

            Log::channel('llm')->error('callback.delivery_failed', [
                'request_id' => $requestLog->request_id,
                'callback_url' => $pending->callback_url,
                'attempts' => $pending->delivery_attempts,
                'last_error' => $result->error,
            ]);
        } else {
            // Schedule retry
            $delay = $this->retryCalculator->calculateDelay(
                $pending->delivery_attempts,
                $pending->retry_backoff ?? 'exponential',
                $pending->retry_initial_delay ?? 1,
            );
            $pending->delivery_status = DeliveryStatus::Pending;
            $pending->next_retry_at = now()->addSeconds($delay);
            $pending->last_error = $truncatedError;
            $pending->save();
        }

        return $result;
    }
}
