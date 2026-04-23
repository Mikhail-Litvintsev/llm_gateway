<?php

declare(strict_types=1);

namespace App\Components\Delivery\Webhook\Enums;

enum WebhookEvent: string
{
    case MessageCompleted = 'message.completed';
    case MessageFailed = 'message.failed';
}
