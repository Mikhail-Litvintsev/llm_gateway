<?php

declare(strict_types=1);

namespace App\Components\Sessions\Enums;

enum MemoryCommand: string
{
    case View = 'view';
    case Create = 'create';
    case StrReplace = 'str_replace';
    case Insert = 'insert';
    case Delete = 'delete';
    case Rename = 'rename';
}
