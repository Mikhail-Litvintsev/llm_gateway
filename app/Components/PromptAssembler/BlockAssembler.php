<?php

namespace App\Components\PromptAssembler;

use App\Components\PromptAssembler\DTO\AssembledMessages;
use App\Components\RequestPipeline\DTO\PromptBlock;
use App\Components\RequestPipeline\Enums\BlockType;

class BlockAssembler
{
    public function __construct(
        private readonly DataBlockFormatter $dataBlockFormatter,
    ) {}

    /** @param PromptBlock[] $blocks */
    public function assemble(array $blocks, string $providerName): AssembledMessages
    {
        $systemParts = [];
        $historyMessages = [];
        $userContentParts = [];
        $assistantParts = [];
        $prefixContent = null;

        // Build description index: for-attribute or positional (description before data)
        $descriptionMap = $this->buildDescriptionMap($blocks);

        foreach ($blocks as $index => $block) {
            $type = BlockType::tryFrom($block->type);

            // Skip descriptions — they are consumed by data blocks
            if ($type === BlockType::Description) {
                continue;
            }

            match ($type) {
                BlockType::System,
                BlockType::Instruction,
                BlockType::Constraint,
                BlockType::OutputFormat => $systemParts[] = $block->content,

                BlockType::History => $historyMessages[] = $this->buildHistoryMessage($block, $providerName),

                BlockType::HistoryToolResult => $historyMessages[] = $this->buildHistoryToolResult($block, $providerName),

                BlockType::Data => $userContentParts[] = [
                    'type' => 'text',
                    'text' => $this->dataBlockFormatter->format($block, $descriptionMap[$block->id ?? "pos_$index"] ?? null),
                ],

                BlockType::Image => $userContentParts[] = $this->buildMediaBlock($block, $providerName),

                BlockType::Document => $userContentParts[] = $this->buildDocumentBlock($block, $providerName),

                BlockType::Audio => $userContentParts[] = $this->buildAudioBlock($block, $providerName),

                BlockType::Example => match ($block->role) {
                    'user' => $historyMessages[] = ['role' => 'user', 'content' => $block->content],
                    'assistant' => $historyMessages[] = ['role' => 'assistant', 'content' => $block->content],
                    default => $userContentParts[] = ['type' => 'text', 'text' => $block->content],
                },

                BlockType::Prefix => $prefixContent = $block->content,

                BlockType::Url => $userContentParts[] = ['type' => 'text', 'text' => $block->content],

                default => $this->appendToRole($block, $systemParts, $userContentParts, $assistantParts),
            };
        }

        // Build messages array
        $messages = [];

        // History messages first
        foreach ($historyMessages as $msg) {
            $messages[] = $msg;
        }

        // Current user message
        if (!empty($userContentParts)) {
            $messages[] = $this->buildUserMessage($userContentParts, $providerName);
        }

        // Current assistant message (few-shot / prefill)
        if (!empty($assistantParts)) {
            $messages[] = [
                'role' => 'assistant',
                'content' => implode("\n\n", $assistantParts),
            ];
        }

        // Prefix as last assistant message
        if ($prefixContent !== null) {
            $messages[] = [
                'role' => 'assistant',
                'content' => $prefixContent,
            ];
        }

        return new AssembledMessages(
            systemPrompt: implode("\n\n", $systemParts),
            messages: $messages,
        );
    }

    /** @param PromptBlock[] $blocks */
    private function buildDescriptionMap(array $blocks): array
    {
        $map = [];

        foreach ($blocks as $index => $block) {
            if (BlockType::tryFrom($block->type) !== BlockType::Description) {
                continue;
            }

            if ($block->for) {
                $map[$block->for] = $block;
            } else {
                // Positional: find next data block
                $nextIndex = $index + 1;
                while ($nextIndex < count($blocks)) {
                    $nextBlock = $blocks[$nextIndex];
                    if (BlockType::tryFrom($nextBlock->type) === BlockType::Data) {
                        $key = $nextBlock->id ?? "pos_$nextIndex";
                        $map[$key] = $block;
                        break;
                    }
                    $nextIndex++;
                }
            }
        }

        return $map;
    }

    private function buildHistoryMessage(PromptBlock $block, string $providerName): array
    {
        $message = ['role' => $block->role, 'content' => $block->content];

        // Handle history with JSON format containing tool_calls
        if ($block->format === 'json' && $block->role === 'assistant') {
            $decoded = json_decode($block->content, true);
            if ($decoded && isset($decoded['tool_calls'])) {
                $message = $this->buildHistoryToolUse($decoded, $providerName);
            }
        }

        return $message;
    }

    private function buildHistoryToolUse(array $decoded, string $providerName): array
    {
        $content = $decoded['content'] ?? '';

        if ($providerName === 'claude') {
            $contentBlocks = [];
            if ($content) {
                $contentBlocks[] = ['type' => 'text', 'text' => $content];
            }
            foreach ($decoded['tool_calls'] as $tc) {
                $contentBlocks[] = [
                    'type' => 'tool_use',
                    'id' => $tc['id'],
                    'name' => $tc['name'],
                    'input' => $tc['arguments'] ?? $tc['input'] ?? [],
                ];
            }

            return ['role' => 'assistant', 'content' => $contentBlocks];
        }

        // OpenAI-compatible
        $toolCalls = [];
        foreach ($decoded['tool_calls'] as $tc) {
            $toolCalls[] = [
                'id' => $tc['id'],
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'],
                    'arguments' => json_encode($tc['arguments'] ?? $tc['input'] ?? []),
                ],
            ];
        }

        $msg = ['role' => 'assistant', 'content' => $content ?: null];
        if (!empty($toolCalls)) {
            $msg['tool_calls'] = $toolCalls;
        }

        return $msg;
    }

    private function buildHistoryToolResult(PromptBlock $block, string $providerName): array
    {
        if ($providerName === 'claude') {
            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $block->toolCallId,
                        'content' => $block->content,
                    ],
                ],
            ];
        }

        // OpenAI-compatible
        return [
            'role' => 'tool',
            'tool_call_id' => $block->toolCallId,
            'content' => $block->content,
        ];
    }

    private function buildMediaBlock(PromptBlock $block, string $providerName): array
    {
        if ($providerName === 'claude') {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType ?? 'image/png',
                    'data' => $block->content,
                ],
            ];
        }

        if ($providerName === 'gemini') {
            return [
                'inlineData' => [
                    'mimeType' => $block->mediaType ?? 'image/png',
                    'data' => $block->content,
                ],
            ];
        }

        // OpenAI-compatible: data URL
        $mimeType = $block->mediaType ?? 'image/png';

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:{$mimeType};base64,{$block->content}",
            ],
        ];
    }

    private function buildDocumentBlock(PromptBlock $block, string $providerName): array
    {
        if ($providerName === 'claude') {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType ?? 'application/pdf',
                    'data' => $block->content,
                ],
            ];
        }

        // For other providers, treat as text attachment
        return [
            'type' => 'text',
            'text' => "[Document: {$block->mediaType}]\n{$block->content}",
        ];
    }

    private function buildAudioBlock(PromptBlock $block, string $providerName): array
    {
        if ($providerName === 'gemini') {
            return [
                'inlineData' => [
                    'mimeType' => $block->mediaType ?? 'audio/mp3',
                    'data' => $block->content,
                ],
            ];
        }

        // For other providers, include as-is
        return [
            'type' => 'text',
            'text' => "[Audio: {$block->mediaType}]",
        ];
    }

    private function buildUserMessage(array $contentParts, string $providerName): array
    {
        // If only one text part, simplify to string content
        if (count($contentParts) === 1 && ($contentParts[0]['type'] ?? '') === 'text') {
            return [
                'role' => 'user',
                'content' => $contentParts[0]['text'],
            ];
        }

        return [
            'role' => 'user',
            'content' => $contentParts,
        ];
    }

    private function appendToRole(
        PromptBlock $block,
        array &$systemParts,
        array &$userContentParts,
        array &$assistantParts,
    ): void {
        match ($block->role) {
            'system' => $systemParts[] = $block->content,
            'assistant' => $assistantParts[] = $block->content,
            default => $userContentParts[] = ['type' => 'text', 'text' => $block->content],
        };
    }
}
