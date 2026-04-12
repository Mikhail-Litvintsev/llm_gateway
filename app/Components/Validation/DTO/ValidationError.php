<?php

declare(strict_types=1);

namespace App\Components\Validation\DTO;

final readonly class ValidationError
{
    public function __construct(
        public string $path,
        public string $code,
        public string $message,
    ) {}
}
