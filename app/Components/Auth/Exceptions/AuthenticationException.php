<?php

declare(strict_types=1);

namespace App\Components\Auth\Exceptions;

final class AuthenticationException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
