<?php

declare(strict_types=1);

namespace App\Components\Validation\Exceptions;

use App\Components\Validation\DTO\ValidationResult;

final class ValidationException extends \RuntimeException
{
    public function __construct(
        public readonly ValidationResult $result,
    ) {
        $firstMessage = $result->errors[0]->message ?? 'Validation failed';
        parent::__construct($firstMessage);
    }
}
