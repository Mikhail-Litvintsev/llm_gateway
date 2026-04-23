<?php

declare(strict_types=1);

namespace App\Components\Validation\Exceptions;

use RuntimeException;

final class FeatureNotAllowedException extends RuntimeException
{
    public function __construct(string $feature)
    {
        parent::__construct("Feature '$feature' is not enabled for this client", 403);
    }
}
