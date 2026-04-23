<?php

declare(strict_types=1);

namespace App\Components\Claude\Enums;

enum BatchItemStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Errored = 'errored';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
