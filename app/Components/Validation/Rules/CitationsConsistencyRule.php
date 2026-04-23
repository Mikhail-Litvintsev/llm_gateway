<?php

declare(strict_types=1);

namespace App\Components\Validation\Rules;

use App\Components\Validation\DTO\ValidationError;

final class CitationsConsistencyRule
{
    public function check(array $payload): ?ValidationError
    {
        $blocks = $this->collectCitableBlocks($payload);

        if ($blocks === []) {
            return null;
        }

        $enabledCount = 0;
        $disabledCount = 0;

        foreach ($blocks as $block) {
            $enabled = $block['citations']['enabled'] ?? false;
            if ($enabled === true) {
                $enabledCount++;
            } else {
                $disabledCount++;
            }
        }

        if ($enabledCount > 0 && $disabledCount > 0) {
            return new ValidationError(
                '/messages',
                'citations_all_or_nothing',
                'Citations must be enabled on all document/search_result blocks or none',
            );
        }

        if ($enabledCount > 0 && isset($payload['output_config'])) {
            return new ValidationError(
                '/output_config',
                'citations_vs_output_config',
                'Citations cannot be combined with output_config',
            );
        }

        return null;
    }

    private function collectCitableBlocks(array $payload): array
    {
        $blocks = [];

        foreach ($payload['messages'] ?? [] as $message) {
            $content = $message['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $type = $block['type'] ?? '';
                if ($type === 'document' || $type === 'search_result') {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }
}
