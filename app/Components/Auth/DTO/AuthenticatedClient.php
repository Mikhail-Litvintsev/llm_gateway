<?php

namespace App\Components\Auth\DTO;

readonly class AuthenticatedClient
{
    public function __construct(
        public int $id,
        public string $name,
        public int $rateLimit,
        public ?array $allowedProviders,
        public string $signingSecret,
        public bool $devMode,
    ) {}
}
