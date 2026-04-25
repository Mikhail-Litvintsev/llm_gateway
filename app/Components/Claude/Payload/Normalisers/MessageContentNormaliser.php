<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Normalisers;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;

final readonly class MessageContentNormaliser
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     *
     * @throws PayloadBuildException
     */
    public function normalise(array $messages): array
    {
        foreach ($messages as &$message) {
            $content = $message['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as &$block) {
                if (! is_array($block) || ($block['type'] ?? '') !== 'search_result') {
                    continue;
                }
                $block = $this->normaliseSearchResultBlock($block);
            }
            unset($block);

            $message['content'] = $content;
        }
        unset($message);

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     *
     * @throws PayloadBuildException
     */
    private function normaliseSearchResultBlock(array $block): array
    {
        $allowedKeys = ['type', 'title', 'source', 'content', 'citations'];
        $unknown = array_diff(array_keys($block), $allowedKeys);

        if ($unknown !== []) {
            throw PayloadBuildException::invalidRequest(
                'Unknown key on search_result block: '.reset($unknown)
            );
        }

        foreach (['title', 'source', 'content'] as $required) {
            if (! isset($block[$required])) {
                throw PayloadBuildException::invalidRequest(
                    "search_result block missing required key: $required"
                );
            }
        }

        foreach ($block['content'] as $inner) {
            if (! is_array($inner) || ($inner['type'] ?? '') !== 'text') {
                throw PayloadBuildException::invalidRequest(
                    'search_result.content only accepts text blocks'
                );
            }
        }

        if (isset($block['citations']) && (! is_array($block['citations']) || ! array_key_exists('enabled', $block['citations']))) {
            throw PayloadBuildException::invalidRequest(
                'search_result: citations must be {enabled: bool}'
            );
        }

        return $block;
    }
}
