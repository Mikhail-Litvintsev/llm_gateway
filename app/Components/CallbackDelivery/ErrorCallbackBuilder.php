<?php

namespace App\Components\CallbackDelivery;

class ErrorCallbackBuilder
{
    public function build(
        array $metaData,
        string $errorCode,
        string $errorMessage,
        array $details = [],
        int $latencyMs = 0,
    ): array {
        return [
            'status' => 'error',
            'meta' => $metaData,
            'error' => [
                'code' => $errorCode,
                'message' => $errorMessage,
                'details' => $details ?: new \stdClass(),
            ],
            'latency_ms' => $latencyMs,
        ];
    }
}
