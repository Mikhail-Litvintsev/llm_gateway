<?php

namespace App\Components\CallbackDelivery;

use App\Components\CallbackDelivery\DTO\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

class CallbackSender
{
    public function __construct(
        private readonly Factory $http,
    ) {}

    public function send(string $url, string $method, array $payload, array $headers): DeliveryResult
    {
        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout(config('llm.callback.max_response_wait', 10))
                ->{strtolower($method)}($url, $payload);

            $success = in_array($response->status(), [200, 202]);

            return new DeliveryResult(
                success: $success,
                httpStatus: $response->status(),
                error: $success ? null : "HTTP {$response->status()}: {$response->body()}",
            );
        } catch (ConnectionException $e) {
            return new DeliveryResult(
                success: false,
                httpStatus: 0,
                error: "Connection error: {$e->getMessage()}",
            );
        }
    }
}
