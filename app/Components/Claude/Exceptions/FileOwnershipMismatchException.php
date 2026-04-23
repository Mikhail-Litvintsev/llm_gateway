<?php

declare(strict_types=1);

namespace App\Components\Claude\Exceptions;

use RuntimeException;

final class FileOwnershipMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $fileId,
        public readonly int $requestingClientId,
        public readonly int $ownerClientId,
    ) {
        parent::__construct("File $fileId does not belong to requesting client");
    }
}
