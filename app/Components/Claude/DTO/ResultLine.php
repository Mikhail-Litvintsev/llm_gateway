<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class ResultLine
{
    /**
     * @param  array<string, mixed>|null  $message
     * @param  array<string, mixed>|null  $error
     */
    public function __construct(
        public string $customId,
        public string $type,
        public ?array $message = null,
        public ?array $error = null,
    ) {}
}
