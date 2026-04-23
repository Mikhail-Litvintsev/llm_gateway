<?php

declare(strict_types=1);

namespace App\Components\Logging\Enums;

enum Endpoint: string
{
    case Messages = 'messages';
    case BatchItem = 'batch_item';
    case CountTokens = 'count_tokens';
    case SessionMessage = 'session_message';
}
