<?php

namespace App\Components\CallbackDelivery\Enums;

enum CallbackEventType: string
{
    case Completion = 'completion';
    case Error = 'error';
    case StreamToken = 'stream_token';
    case StreamDone = 'stream_done';
    case StreamError = 'stream_error';
}
