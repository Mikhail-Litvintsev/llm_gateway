<?php

declare(strict_types=1);

namespace App\Components\Messaging\Exceptions;

use App\Components\Authorization\DTO\AuthorizationResult;
use RuntimeException;

final class MessageProcessingException extends RuntimeException
{
    public const string KIND_VALIDATION = 'validation';

    public const string KIND_AUTHORIZATION = 'authorization';

    public const string KIND_BILLING = 'billing';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly string $gatewayRequestId,
        public readonly ?AuthorizationResult $authorizationResult = null,
        public readonly string $modelAlias = '',
        public readonly string $modelSnapshot = '',
    ) {
        parent::__construct($message);
    }

    public static function validationFailed(string $message, string $gatewayRequestId): self
    {
        return new self(self::KIND_VALIDATION, $message, $gatewayRequestId);
    }

    public static function authorizationDenied(
        AuthorizationResult $result,
        string $gatewayRequestId,
        string $modelAlias,
        string $modelSnapshot,
    ): self {
        return new self(
            self::KIND_AUTHORIZATION,
            $result->message ?? 'Authorization denied',
            $gatewayRequestId,
            $result,
            $modelAlias,
            $modelSnapshot,
        );
    }

    public static function billingCapExceeded(
        string $gatewayRequestId,
        string $modelAlias,
        string $modelSnapshot,
    ): self {
        return new self(
            self::KIND_BILLING,
            'Monthly spend cap exceeded.',
            $gatewayRequestId,
            null,
            $modelAlias,
            $modelSnapshot,
        );
    }
}
