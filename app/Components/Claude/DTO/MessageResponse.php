<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class MessageResponse
{
    public function __construct(
        public string  $anthropicId,
        public string  $role,
        public array   $content,
        public string  $model,
        public ?string $stopReason,
        public array   $usage,
        public ?string $anthropicRequestId,
        public ?string $anthropicOrganizationId,
        public array   $rateLimitHeaders,
    ) {}
}
