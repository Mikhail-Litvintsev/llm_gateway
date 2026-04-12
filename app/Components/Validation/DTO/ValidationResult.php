<?php

declare(strict_types=1);

namespace App\Components\Validation\DTO;

final readonly class ValidationResult
{
    /** @param ValidationError[] $errors */
    public function __construct(
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
