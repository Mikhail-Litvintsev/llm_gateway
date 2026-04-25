<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;

final readonly class CitationsStructuredOutputEnforcer
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws PayloadBuildException
     */
    public function enforce(array $payload): void
    {
        $citationsEnabled = ($payload['citations']['enabled'] ?? false) === true;
        $hasOutputFormat = isset($payload['output_config']['format']);

        if ($citationsEnabled && $hasOutputFormat) {
            throw PayloadBuildException::invalidRequest(
                'Citations and structured output formats are mutually exclusive'
            );
        }
    }
}
