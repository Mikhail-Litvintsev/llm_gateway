<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch\Accumulator\Exceptions;

use RuntimeException;

final class CallbackUrlMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('callback_url_mismatch_within_bucket');
    }
}
