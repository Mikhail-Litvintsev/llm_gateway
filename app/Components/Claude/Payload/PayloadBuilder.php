<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload;

use App\Components\Claude\DTO\MessageRequest;

/** Phase 1 scaffold — tools, thinking, cache_control added in Phase 2-4. */
final class PayloadBuilder
{
    public function buildMessagesPayload(MessageRequest $request, string $modelSnapshot): array
    {
        return [
            'model' => $modelSnapshot,
            'messages' => $request->messages,
            'max_tokens' => $request->maxTokens,
        ];
    }
}
