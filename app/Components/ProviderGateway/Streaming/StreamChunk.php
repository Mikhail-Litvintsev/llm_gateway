<?php

namespace App\Components\ProviderGateway\Streaming;

use App\Components\ProviderGateway\DTO\UsageInfo;

readonly class StreamChunk
{
    public function __construct(
        public string $type,           // 'token', 'done', 'error'
        public ?string $content,       // текст токена (для type=token)
        public int $index,             // порядковый номер токена
        public ?string $finishReason,  // для type=done
        public ?UsageInfo $usage,      // для type=done
        public ?string $errorCode,     // для type=error
        public ?string $errorMessage,  // для type=error
    ) {}
}
