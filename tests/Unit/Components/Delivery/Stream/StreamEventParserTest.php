<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Delivery\Stream;

use App\Components\Delivery\Stream\StreamEventParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamEventParserTest extends TestCase
{
    private StreamEventParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new StreamEventParser();
    }

    #[Test]
    public function aggregate_starts_with_clean_state(): void
    {
        $agg = $this->parser->aggregate();

        $this->assertNull($agg->inputTokens);
        $this->assertNull($agg->outputTokens);
        $this->assertNull($agg->stopReason);
        $this->assertSame(0, $agg->eventsSeen);
        $this->assertFalse($agg->completed);
        $this->assertFalse($agg->errored);
        $this->assertSame(0, $agg->malformedEventCount);
    }

    #[Test]
    public function message_start_extracts_usage_and_service_tier(): void
    {
        $data = json_encode([
            'message' => [
                'usage' => [
                    'input_tokens' => 100,
                    'cache_creation_input_tokens' => 50,
                    'cache_read_input_tokens' => 25,
                ],
                'service_tier' => 'standard',
            ],
        ]);

        $this->parser->consume('message_start', $data);

        $agg = $this->parser->aggregate();
        $this->assertSame(100, $agg->inputTokens);
        $this->assertSame(50, $agg->cacheCreationInputTokens);
        $this->assertSame(25, $agg->cacheReadInputTokens);
        $this->assertSame('standard', $agg->serviceTier);
        $this->assertSame(1, $agg->eventsSeen);
    }

    #[Test]
    public function message_delta_extracts_stop_reason_and_output_tokens(): void
    {
        $data = json_encode([
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 42],
        ]);

        $this->parser->consume('message_delta', $data);

        $agg = $this->parser->aggregate();
        $this->assertSame('end_turn', $agg->stopReason);
        $this->assertSame(42, $agg->outputTokens);
    }

    #[Test]
    public function message_delta_updates_input_tokens_when_present(): void
    {
        $data = json_encode([
            'delta' => [],
            'usage' => ['input_tokens' => 150, 'output_tokens' => 10],
        ]);

        $this->parser->consume('message_delta', $data);

        $this->assertSame(150, $this->parser->aggregate()->inputTokens);
    }

    #[Test]
    public function message_stop_sets_completed(): void
    {
        $this->parser->consume('message_stop', json_encode([]));

        $this->assertTrue($this->parser->aggregate()->completed);
    }

    #[Test]
    public function error_event_sets_errored_and_anthropic_error(): void
    {
        $data = json_encode([
            'error' => ['type' => 'overloaded_error', 'message' => 'Server busy'],
        ]);

        $this->parser->consume('error', $data);

        $agg = $this->parser->aggregate();
        $this->assertTrue($agg->errored);
        $this->assertSame('overloaded_error', $agg->anthropicError);
    }

    #[Test]
    public function ping_event_increments_events_seen_only(): void
    {
        $this->parser->consume('ping', json_encode([]));

        $agg = $this->parser->aggregate();
        $this->assertSame(1, $agg->eventsSeen);
        $this->assertNull($agg->inputTokens);
        $this->assertFalse($agg->completed);
    }

    #[Test]
    public function content_block_events_increment_events_seen(): void
    {
        $this->parser->consume('content_block_start', json_encode(['content_block' => ['type' => 'text']]));
        $this->parser->consume('content_block_delta', json_encode(['delta' => ['type' => 'text_delta', 'text' => 'Hi']]));
        $this->parser->consume('content_block_stop', json_encode([]));

        $this->assertSame(3, $this->parser->aggregate()->eventsSeen);
    }

    #[Test]
    public function malformed_json_is_tolerated_and_counted(): void
    {
        $this->parser->consume('message_start', '{broken json');
        $this->parser->consume('message_delta', '');

        $agg = $this->parser->aggregate();
        $this->assertSame(2, $agg->eventsSeen);
        $this->assertSame(2, $agg->malformedEventCount);
        $this->assertNull($agg->inputTokens);
    }

    #[Test]
    public function unknown_event_type_is_silently_ignored(): void
    {
        $this->parser->consume('some_future_event', json_encode(['data' => true]));

        $agg = $this->parser->aggregate();
        $this->assertSame(1, $agg->eventsSeen);
        $this->assertFalse($agg->completed);
        $this->assertFalse($agg->errored);
    }

    #[Test]
    public function reset_clears_accumulated_state(): void
    {
        $this->parser->consume('message_start', json_encode([
            'message' => ['usage' => ['input_tokens' => 50]],
        ]));
        $this->parser->consume('message_stop', json_encode([]));

        $this->parser->reset();

        $agg = $this->parser->aggregate();
        $this->assertNull($agg->inputTokens);
        $this->assertSame(0, $agg->eventsSeen);
        $this->assertFalse($agg->completed);
    }

    #[Test]
    public function full_stream_lifecycle(): void
    {
        $this->parser->consume('message_start', json_encode([
            'message' => [
                'usage' => ['input_tokens' => 200, 'cache_read_input_tokens' => 10],
                'service_tier' => 'standard',
            ],
        ]));
        $this->parser->consume('content_block_start', json_encode(['content_block' => ['type' => 'text']]));
        $this->parser->consume('content_block_delta', json_encode(['delta' => ['type' => 'text_delta', 'text' => 'Hello']]));
        $this->parser->consume('content_block_stop', json_encode([]));
        $this->parser->consume('message_delta', json_encode([
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 15],
        ]));
        $this->parser->consume('message_stop', json_encode([]));

        $agg = $this->parser->aggregate();
        $this->assertSame(200, $agg->inputTokens);
        $this->assertSame(15, $agg->outputTokens);
        $this->assertSame(10, $agg->cacheReadInputTokens);
        $this->assertSame('end_turn', $agg->stopReason);
        $this->assertSame('standard', $agg->serviceTier);
        $this->assertTrue($agg->completed);
        $this->assertFalse($agg->errored);
        $this->assertSame(6, $agg->eventsSeen);
        $this->assertSame(0, $agg->malformedEventCount);
    }
}
