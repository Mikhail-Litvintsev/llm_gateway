<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload;

final readonly class PayloadInspector
{
    /**
     * Checks whether the payload (or any of its system/messages blocks) contains cache_control markers.
     *
     * Uses isset() semantics — a cache_control key set to null is treated as absent.
     * See AutoCacheInjector for array_key_exists variant (intentionally separate).
     *
     * @param  array<string, mixed>  $payload
     */
    public function hasCacheControl(array $payload): bool
    {
        if (isset($payload['cache_control'])) {
            return true;
        }

        foreach ($payload['system'] ?? [] as $block) {
            if (is_array($block) && isset($block['cache_control'])) {
                return true;
            }
        }

        foreach ($payload['messages'] ?? [] as $message) {
            $content = $message['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (is_array($block) && isset($block['cache_control'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
