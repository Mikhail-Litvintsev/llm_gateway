<?php

namespace App\Components\RequestPipeline\Enums;

enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
