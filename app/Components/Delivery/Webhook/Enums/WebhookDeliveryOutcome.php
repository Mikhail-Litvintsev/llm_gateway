<?php

declare(strict_types=1);

namespace App\Components\Delivery\Webhook\Enums;

enum WebhookDeliveryOutcome: string
{
    case Success = 'success';
    case PermanentFail = 'permanent_fail';
    case TransientFail = 'transient_fail';
}
