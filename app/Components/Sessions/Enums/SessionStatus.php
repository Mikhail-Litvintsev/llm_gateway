<?php

declare(strict_types=1);

namespace App\Components\Sessions\Enums;

enum SessionStatus: string
{
    case Active = 'active';
    case Deleted = 'deleted';
    case Expired = 'expired';
    case Archived = 'archived';
}
