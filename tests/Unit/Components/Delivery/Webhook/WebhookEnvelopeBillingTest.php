<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Delivery\Webhook;

use App\Components\Delivery\Webhook\DTO\WebhookEnvelope;
use App\Components\Delivery\Webhook\Enums\WebhookEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookEnvelopeBillingTest extends TestCase
{
    #[Test]
    public function billing_accepts_null_remaining_when_no_spend_cap(): void
    {
        $envelope = new WebhookEnvelope(
            requestId: 'req_unlimited',
            event: WebhookEvent::MessageCompleted,
            billing: [
                'cost_usd' => 0.0123,
                'cost_breakdown' => ['input' => 0.01, 'output' => 0.0023],
                'monthly_spend_after_usd' => 12.50,
                'monthly_spend_remaining_usd' => null,
            ],
        );

        $array = $envelope->toArray();

        $this->assertArrayHasKey('billing', $array);
        $this->assertNull($array['billing']['monthly_spend_remaining_usd']);
    }

    #[Test]
    public function billing_accepts_float_remaining_when_spend_cap_set(): void
    {
        $envelope = new WebhookEnvelope(
            requestId: 'req_capped',
            event: WebhookEvent::MessageCompleted,
            billing: [
                'cost_usd' => 0.05,
                'cost_breakdown' => [],
                'monthly_spend_after_usd' => 50.00,
                'monthly_spend_remaining_usd' => 50.00,
            ],
        );

        $array = $envelope->toArray();

        $this->assertSame(50.00, $array['billing']['monthly_spend_remaining_usd']);
    }
}
