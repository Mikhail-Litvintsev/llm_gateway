<?php

declare(strict_types=1);

namespace App\Components\Delivery\Stream;

use App\Components\Delivery\Stream\DTO\StreamAggregate;
use App\Components\Delivery\Stream\Enums\StreamEventType;
use JsonException;

final class StreamEventParser
{
    private ?int $inputTokens = null;

    private ?int $outputTokens = null;

    private ?int $cacheCreationInputTokens = null;

    private ?int $cacheReadInputTokens = null;

    private ?int $thinkingTokens = null;

    private ?string $stopReason = null;

    private ?string $serviceTier = null;

    private ?string $anthropicError = null;

    private int $eventsSeen = 0;

    private bool $completed = false;

    private bool $errored = false;

    private int $malformedEventCount = 0;

    /**
     * @param  string  $eventName  SSE event name (e.g. "message_start", "content_block_delta")
     * @param  string  $dataJson  Raw JSON string from the SSE data line
     */
    public function consume(string $eventName, string $dataJson): void
    {
        $this->eventsSeen++;

        try {
            $data = json_decode($dataJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->malformedEventCount++;

            return;
        }

        $eventType = StreamEventType::tryFrom($eventName);

        if ($eventType === null) {
            return;
        }

        match ($eventType) {
            StreamEventType::MessageStart => $this->handleMessageStart($data),
            StreamEventType::MessageDelta => $this->handleMessageDelta($data),
            StreamEventType::MessageStop => $this->completed = true,
            StreamEventType::Error => $this->handleError($data),
            StreamEventType::ContentBlockStart,
            StreamEventType::ContentBlockDelta,
            StreamEventType::ContentBlockStop,
            StreamEventType::Ping => null,
        };
    }

    /**
     * @return StreamAggregate Current accumulated state of the stream
     */
    public function aggregate(): StreamAggregate
    {
        return new StreamAggregate(
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            cacheCreationInputTokens: $this->cacheCreationInputTokens,
            cacheReadInputTokens: $this->cacheReadInputTokens,
            thinkingTokens: $this->thinkingTokens,
            stopReason: $this->stopReason,
            serviceTier: $this->serviceTier,
            anthropicError: $this->anthropicError,
            eventsSeen: $this->eventsSeen,
            completed: $this->completed,
            errored: $this->errored,
            malformedEventCount: $this->malformedEventCount,
        );
    }

    public function reset(): void
    {
        $this->inputTokens = null;
        $this->outputTokens = null;
        $this->cacheCreationInputTokens = null;
        $this->cacheReadInputTokens = null;
        $this->thinkingTokens = null;
        $this->stopReason = null;
        $this->serviceTier = null;
        $this->anthropicError = null;
        $this->eventsSeen = 0;
        $this->completed = false;
        $this->errored = false;
        $this->malformedEventCount = 0;
    }

    private function handleMessageStart(array $data): void
    {
        $message = $data['message'] ?? [];
        $usage = $message['usage'] ?? [];

        $this->inputTokens = $usage['input_tokens'] ?? $this->inputTokens;
        $this->cacheCreationInputTokens = $usage['cache_creation_input_tokens'] ?? $this->cacheCreationInputTokens;
        $this->cacheReadInputTokens = $usage['cache_read_input_tokens'] ?? $this->cacheReadInputTokens;
        $this->serviceTier = $message['service_tier'] ?? $this->serviceTier;
    }

    private function handleMessageDelta(array $data): void
    {
        $delta = $data['delta'] ?? [];
        $usage = $data['usage'] ?? [];

        $this->stopReason = $delta['stop_reason'] ?? $this->stopReason;
        $this->outputTokens = $usage['output_tokens'] ?? $this->outputTokens;

        if (isset($usage['input_tokens'])) {
            $this->inputTokens = $usage['input_tokens'];
        }
    }

    private function handleError(array $data): void
    {
        $this->errored = true;
        $this->anthropicError = $data['error']['type'] ?? $data['type'] ?? null;
    }
}
