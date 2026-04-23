<?php

declare(strict_types=1);

namespace App\Components\Logging\Enums;

enum Mode: string
{
    case Sync = 'sync';
    case SyncStream = 'sync_stream';
    case AsyncCallback = 'async_callback';
    case Batch = 'batch';
}
