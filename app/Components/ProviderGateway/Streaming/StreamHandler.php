<?php

namespace App\Components\ProviderGateway\Streaming;

use App\Components\ProviderGateway\DTO\UsageInfo;
use Psr\Http\Message\ResponseInterface;

class StreamHandler
{
    /**
     * Принимает SSE-поток от Claude и возвращает генератор чанков.
     *
     * @return \Generator<StreamChunk>
     */
    public function handleClaudeStream(ResponseInterface $response, string $requestId): \Generator
    {
        $body = $response->getBody();
        $buffer = '';
        $index = 0;

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // Неполная строка остаётся в буфере

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                if (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                    if (!$data) {
                        continue;
                    }

                    $chunk = $this->parseClaudeEvent($data, $index);
                    if ($chunk) {
                        if ($chunk->type === 'token') {
                            $index++;
                        }
                        yield $chunk;
                    }
                }
            }
        }
    }

    /**
     * Принимает SSE-поток от OpenAI-совместимых провайдеров.
     *
     * @return \Generator<StreamChunk>
     */
    public function handleOpenAiStream(ResponseInterface $response, string $requestId): \Generator
    {
        $body = $response->getBody();
        $buffer = '';
        $index = 0;

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                if (str_starts_with($line, 'data: ')) {
                    $raw = substr($line, 6);
                    if ($raw === '[DONE]') {
                        continue;
                    }

                    $data = json_decode($raw, true);
                    if (!$data) {
                        continue;
                    }

                    $chunk = $this->parseOpenAiEvent($data, $index);
                    if ($chunk) {
                        if ($chunk->type === 'token') {
                            $index++;
                        }
                        yield $chunk;
                    }
                }
            }
        }
    }

    private function parseClaudeEvent(array $data, int $index): ?StreamChunk
    {
        $type = $data['type'] ?? '';

        return match ($type) {
            'content_block_delta' => new StreamChunk(
                type: 'token',
                content: $data['delta']['text'] ?? '',
                index: $index,
                finishReason: null,
                usage: null,
                errorCode: null,
                errorMessage: null,
            ),
            'message_delta' => new StreamChunk(
                type: 'done',
                content: null,
                index: $index,
                finishReason: $data['delta']['stop_reason'] ?? 'end_turn',
                usage: new UsageInfo(
                    inputTokens: $data['usage']['input_tokens'] ?? 0,
                    outputTokens: $data['usage']['output_tokens'] ?? 0,
                ),
                errorCode: null,
                errorMessage: null,
            ),
            'error' => new StreamChunk(
                type: 'error',
                content: null,
                index: $index,
                finishReason: null,
                usage: null,
                errorCode: 'STREAM_INTERRUPTED',
                errorMessage: $data['error']['message'] ?? 'Stream error',
            ),
            default => null,
        };
    }

    private function parseOpenAiEvent(array $data, int $index): ?StreamChunk
    {
        $choice = $data['choices'][0] ?? null;
        if (!$choice) {
            return null;
        }

        $delta = $choice['delta'] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;

        if ($finishReason !== null) {
            $usage = $data['usage'] ?? [];
            return new StreamChunk(
                type: 'done',
                content: null,
                index: $index,
                finishReason: $finishReason,
                usage: new UsageInfo(
                    inputTokens: $usage['prompt_tokens'] ?? 0,
                    outputTokens: $usage['completion_tokens'] ?? 0,
                ),
                errorCode: null,
                errorMessage: null,
            );
        }

        $content = $delta['content'] ?? null;
        if ($content !== null && $content !== '') {
            return new StreamChunk(
                type: 'token',
                content: $content,
                index: $index,
                finishReason: null,
                usage: null,
                errorCode: null,
                errorMessage: null,
            );
        }

        return null;
    }
}
