<?php

declare(strict_types=1);

namespace App\Components\Claude\Response;

use App\Components\Claude\DTO\MessageResponse;

/** Phase 1 scaffold — full content-block decoding lands in Phase 2. */
final class ResponseParser
{
    public function parseMessageResponse(array $body, array $headers): MessageResponse
    {
        $rateLimitHeaders = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);
            if (str_starts_with($lower, 'anthropic-ratelimit-')) {
                $rateLimitHeaders[$lower] = is_array($value) ? $value[0] : $value;
            }
        }

        return new MessageResponse(
            anthropicId: $body['id'] ?? '',
            role: $body['role'] ?? 'assistant',
            content: $body['content'] ?? [],
            model: $body['model'] ?? '',
            stopReason: $body['stop_reason'] ?? null,
            usage: $body['usage'] ?? [],
            anthropicRequestId: $this->headerValue($headers, 'request-id'),
            anthropicOrganizationId: $this->headerValue($headers, 'anthropic-organization-id'),
            rateLimitHeaders: $rateLimitHeaders,
        );
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? $value[0] : (string) $value;
            }
        }

        return null;
    }
}
