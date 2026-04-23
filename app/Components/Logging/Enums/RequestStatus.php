<?php

declare(strict_types=1);

namespace App\Components\Logging\Enums;

enum RequestStatus: string
{
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CompletedDisconnected = 'completed_disconnected';
    case FailedClientError = 'failed_client_error';
    case FailedServerError = 'failed_server_error';
    case FailedCallbackDelivery = 'failed_callback_delivery';
    case FailedValidation = 'failed_validation';
    case FailedAuth = 'failed_auth';
}
