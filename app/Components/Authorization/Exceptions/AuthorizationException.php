<?php

declare(strict_types=1);

namespace App\Components\Authorization\Exceptions;

use App\Components\Authorization\DTO\AuthorizationResult;
use RuntimeException;

final class AuthorizationException extends RuntimeException
{
    public function __construct(
        private readonly AuthorizationResult $result,
    ) {
        parent::__construct(
            $result->message ?? 'Authorization denied',
            $result->reason?->httpStatusCode() ?? 403,
        );
    }

    public function httpStatusCode(): int
    {
        return $this->result->reason?->httpStatusCode() ?? 403;
    }

    /**
     * @return array{type: string, error: array{type: string, message: string}}
     */
    public function toErrorBody(): array
    {
        return [
            'type' => 'error',
            'error' => [
                'type' => $this->result->reason?->errorType() ?? 'permission_error',
                'message' => $this->result->message ?? 'Authorization denied',
            ],
        ];
    }

    public function getResult(): AuthorizationResult
    {
        return $this->result;
    }
}
