<?php

declare(strict_types=1);

namespace App\Components\Claude\Enums;

enum BatchStatus: string
{
    case Created = 'created';
    case Submitting = 'submitting';
    case InProgress = 'in_progress';
    case Fetching = 'fetching';
    case Ended = 'ended';
    case Canceling = 'canceling';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';
}
