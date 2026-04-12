<?php

declare(strict_types=1);

namespace App\Components\Routing\Exceptions;

final class UnknownModelAliasException extends \DomainException
{
    public function __construct(
        public readonly string $alias,
    ) {
        parent::__construct("Unknown model alias: {$alias}");
    }
}
