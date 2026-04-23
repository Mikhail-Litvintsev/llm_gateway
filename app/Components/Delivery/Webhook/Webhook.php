<?php

declare(strict_types=1);

namespace App\Components\Delivery\Webhook;

use App\Components\Delivery\Webhook\DTO\SignedRequest;
use App\Components\Delivery\Webhook\DTO\WebhookEnvelope;
use App\Models\Client;
use JsonException;

final readonly class Webhook
{
    public function __construct(
        private Signer $signer,
    ) {}

    /**
     * @throws Exceptions\SecretUnavailableException
     * @throws JsonException
     */
    public function buildSignedRequest(Client $client, WebhookEnvelope $envelope): SignedRequest
    {
        return $this->signer->sign($client, $envelope);
    }
}
