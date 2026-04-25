<?php

declare(strict_types=1);

namespace App\Components\Validation\Rules;

use App\Components\Validation\DTO\ValidationError;

final class SearchResultBlockRule
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function check(array $payload): ?ValidationError
    {
        foreach ($payload['messages'] ?? [] as $mi => $message) {
            $content = $message['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $bi => $block) {
                if (! is_array($block) || ($block['type'] ?? '') !== 'search_result') {
                    continue;
                }

                $error = $this->validateBlock($block, "/messages/$mi/content/$bi");
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function validateBlock(array $block, string $path): ?ValidationError
    {
        $allowedKeys = ['type', 'title', 'source', 'content', 'citations'];

        $unknown = array_diff(array_keys($block), $allowedKeys);
        if ($unknown !== []) {
            $key = reset($unknown);

            return new ValidationError($path, 'unknown_search_result_key', "Unknown key on search_result block: $key");
        }

        foreach (['title', 'source', 'content'] as $required) {
            if (! isset($block[$required])) {
                return new ValidationError($path, 'missing_search_result_key', "search_result block missing required key: $required");
            }
        }

        foreach ($block['content'] as $inner) {
            if (! is_array($inner) || ($inner['type'] ?? '') !== 'text') {
                return new ValidationError("$path/content", 'invalid_search_result_content', 'search_result.content only accepts text blocks');
            }
        }

        if (isset($block['citations']) && (! is_array($block['citations']) || ! array_key_exists('enabled', $block['citations']))) {
            return new ValidationError("$path/citations", 'invalid_citations', 'search_result: citations must be {enabled: bool}');
        }

        return null;
    }
}
