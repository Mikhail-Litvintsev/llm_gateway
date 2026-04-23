<?php

declare(strict_types=1);

namespace App\Components\Delivery\Stream\Enums;

enum StreamEventType: string
{
    case MessageStart = 'message_start';
    case ContentBlockStart = 'content_block_start';
    case ContentBlockDelta = 'content_block_delta';
    case ContentBlockStop = 'content_block_stop';
    case MessageDelta = 'message_delta';
    case MessageStop = 'message_stop';
    case Ping = 'ping';
    case Error = 'error';
}
