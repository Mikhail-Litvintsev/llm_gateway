<?php

namespace App\Components\CallbackDelivery;

use App\Components\CallbackDelivery\Contracts\CallbackSignerContract;
use App\Components\CallbackDelivery\DTO\DeliveryResult;
use App\Components\CallbackDelivery\Enums\CallbackEventType;
use App\Components\ProviderGateway\Streaming\StreamChunk;
use Illuminate\Http\Client\Factory as HttpFactory;

class StreamingDelivery
{
    public function __construct(
        private readonly CallbackSignerContract $signer,
        private readonly HttpFactory $http,
    ) {}

    /**
     * Отправляет один SSE-event на callback URL.
     */
    public function sendEvent(
        string $url,
        string $method,
        array $headers,
        StreamChunk $chunk,
        string $requestId,
        string $signingSecret,
    ): bool {
        $eventType = match ($chunk->type) {
            'token' => CallbackEventType::StreamToken->value,
            'done' => CallbackEventType::StreamDone->value,
            'error' => CallbackEventType::StreamError->value,
        };

        $payload = $this->buildEventPayload($chunk, $requestId);
        $payloadJson = json_encode($payload);

        $signatureHeaders = $this->signer->sign($payloadJson, $signingSecret, $requestId);

        $allHeaders = array_merge(
            ['Content-Type' => 'application/json; charset=utf-8', 'X-LLM-Event-Type' => $eventType],
            $signatureHeaders,
            $headers,
        );

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->http
                    ->withHeaders($allHeaders)
                    ->timeout(5)
                    ->{strtolower($method)}($url, $payload);

                if (in_array($response->status(), [200, 202])) {
                    return true;
                }

                // 4xx — не ретраить
                if ($response->status() >= 400 && $response->status() < 500) {
                    return false;
                }
            } catch (\Throwable) {
                // Ретраить при ошибках соединения
            }

            if ($attempt < $maxAttempts) {
                usleep($attempt * 100_000); // 100ms, 200ms
            }
        }

        return false;
    }

    private function buildEventPayload(StreamChunk $chunk, string $requestId): array
    {
        return match ($chunk->type) {
            'token' => [
                'request_id' => $requestId,
                'content' => $chunk->content,
                'index' => $chunk->index,
            ],
            'done' => [
                'request_id' => $requestId,
                'finish_reason' => $chunk->finishReason,
                'usage' => [
                    'input_tokens' => $chunk->usage?->inputTokens ?? 0,
                    'output_tokens' => $chunk->usage?->outputTokens ?? 0,
                ],
            ],
            'error' => [
                'request_id' => $requestId,
                'error' => [
                    'code' => $chunk->errorCode,
                    'message' => $chunk->errorMessage,
                ],
            ],
        };
    }
}
