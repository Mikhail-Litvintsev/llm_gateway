<?php

declare(strict_types=1);

namespace App\Components\Validation\Exceptions;

use RuntimeException;

final class FeatureQuotaExhaustedException extends RuntimeException
{
    public function __construct(string $message = 'Code execution free-hours pool exhausted for this workspace; contact support')
    {
        parent::__construct($message, 429);
    }
}
