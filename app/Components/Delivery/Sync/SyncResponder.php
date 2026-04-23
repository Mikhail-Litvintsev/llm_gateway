<?php

declare(strict_types=1);

namespace App\Components\Delivery\Sync;

use App\Components\Delivery\Sync\DTO\AnthropicResponseEnvelope;
use App\Components\Delivery\Sync\DTO\GatewayHeaders;
use Illuminate\Http\Response;

final class SyncResponder
{
    /**
     * @param  AnthropicResponseEnvelope  $envelope  Raw Anthropic response (body forwarded byte-for-byte)
     * @param  GatewayHeaders  $gateway  Gateway metadata emitted as X-Gateway-* headers
     */
    public function respond(
        AnthropicResponseEnvelope $envelope,
        GatewayHeaders $gateway,
    ): Response {
        $response = new Response($envelope->rawBody, $envelope->httpStatusCode);

        foreach ($envelope->anthropicHeaders as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->set('Content-Type', $envelope->anthropicHeaders['content-type'] ?? 'application/json');

        $response->headers->set('X-Gateway-Request-Id', $gateway->gatewayRequestId);

        if ($gateway->anthropicRequestId !== null) {
            $response->headers->set('X-Gateway-Anthropic-Request-Id', $gateway->anthropicRequestId);
        }

        $response->headers->set('X-Gateway-Model-Alias', $gateway->modelAlias);
        $response->headers->set('X-Gateway-Model-Snapshot', $gateway->modelSnapshot);
        $response->headers->set('X-Gateway-Cost-USD', number_format($gateway->costUsd, 6, '.', ''));
        $response->headers->set('X-Gateway-Cost-Breakdown', base64_encode(json_encode($gateway->costBreakdown)));

        $response->headers->set(
            'X-Gateway-Spend-Remaining-USD',
            $gateway->spendRemainingUsd !== null
                ? number_format($gateway->spendRemainingUsd, 6, '.', '')
                : 'unlimited',
        );

        if ($gateway->serviceTierUsed !== null) {
            $response->headers->set('X-Gateway-Service-Tier-Used', $gateway->serviceTierUsed);
        }

        if ($gateway->cacheHitTokens !== null) {
            $response->headers->set('X-Gateway-Cache-Hit-Tokens', (string) $gateway->cacheHitTokens);
        }

        return $response;
    }
}
