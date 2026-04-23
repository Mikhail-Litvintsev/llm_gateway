<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class BatchCreateRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $requests
     */
    public function __construct(
        public array $requests,
        public bool $submitImmediately = true,
        public ?string $callbackUrl = null,
        public ?bool $autoUse1hCache = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            requests: $data['requests'] ?? [],
            submitImmediately: $data['submit_immediately'] ?? true,
            callbackUrl: $data['callback_url'] ?? null,
            autoUse1hCache: $data['auto_use_1h_cache_for_batch'] ?? null,
        );
    }
}
