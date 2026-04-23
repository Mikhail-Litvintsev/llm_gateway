<?php

declare(strict_types=1);

namespace App\Components\Claude\Exceptions;

use RuntimeException;

final class FileNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $fileId,
    ) {
        parent::__construct("File not found: $fileId");
    }
}
