<?php

declare(strict_types=1);

namespace App\Components\Validation;

use App\Components\Validation\DTO\ValidationResult;
use App\Models\Client;

final class Validation
{
    public function __construct(
        private readonly MessageRequestValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validateMessageRequest(array $payload, ValidationContext $ctx, Client $client): ValidationResult
    {
        return $this->validator->validate($payload, $ctx, $client);
    }
}
