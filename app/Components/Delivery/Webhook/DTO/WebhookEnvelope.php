<?php

declare(strict_types=1);

namespace App\Components\Delivery\Webhook\DTO;

use App\Components\Delivery\Webhook\Enums\WebhookEvent;

final readonly class WebhookEnvelope
{
    /**
     * @param  array<string, mixed>|null  $anthropicResponse
     * @param  array{type: string, message: string}|null  $error
     * @param  array{cost_usd: float, cost_breakdown: array<string, mixed>, monthly_spend_after_usd: float, monthly_spend_remaining_usd: float}|null  $billing
     */
    public function __construct(
        public string $requestId,
        public WebhookEvent $event,
        public ?string $anthropicRequestId = null,
        public ?string $modelAlias = null,
        public ?string $modelSnapshot = null,
        public ?array $anthropicResponse = null,
        public ?array $error = null,
        public ?array $billing = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'request_id' => $this->requestId,
            'event' => $this->event->value,
        ];

        if ($this->anthropicRequestId !== null) {
            $data['anthropic_request_id'] = $this->anthropicRequestId;
        }

        if ($this->modelAlias !== null) {
            $data['model_alias'] = $this->modelAlias;
        }

        if ($this->modelSnapshot !== null) {
            $data['model_snapshot'] = $this->modelSnapshot;
        }

        if ($this->anthropicResponse !== null) {
            $data['anthropic_response'] = $this->anthropicResponse;
        }

        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        if ($this->billing !== null) {
            $data['billing'] = $this->billing;
        }

        return $data;
    }
}
