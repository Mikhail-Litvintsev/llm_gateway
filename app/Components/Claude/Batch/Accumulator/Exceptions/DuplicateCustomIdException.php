<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch\Accumulator\Exceptions;

use RuntimeException;

final class DuplicateCustomIdException extends RuntimeException
{
    public function __construct(string $customId)
    {
        parent::__construct("Duplicate custom_id within bucket: {$customId}");
    }
}
