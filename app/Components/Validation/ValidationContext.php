<?php

declare(strict_types=1);

namespace App\Components\Validation;

enum ValidationContext: string
{
    case Sync          = 'sync';
    case SyncStream    = 'sync_stream';
    case AsyncCallback = 'async_callback';
    case BatchItem     = 'batch_item';
    case Session       = 'session';
    case CountTokens   = 'count_tokens';
}
