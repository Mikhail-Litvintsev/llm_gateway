<?php

namespace App\Components\RequestPipeline\Enums;

enum BlockRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
