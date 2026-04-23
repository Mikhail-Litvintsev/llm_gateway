<?php

namespace App\Components\RequestPipeline\Enums;

enum RequestStatus: string
{
    case Accepted = 'accepted';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Timeout = 'timeout';
}
