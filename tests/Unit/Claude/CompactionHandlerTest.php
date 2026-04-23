<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\Response\ResponseParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionHandlerTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ResponseParser();
    }

    #[Test]
    public function detects_compaction_by_content_block(): void
    {
        $body = $this->makeBody(
            content: [['type' => 'compaction', 'summary' => 'Context was compacted']],
            stopReason: 'end_turn',
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
        );

        $response = $this->parser->parseMessageResponse($body, []);

        $this->assertTrue($response->compactionDetected);
    }

    #[Test]
    public function detects_compaction_by_iterations_usage(): void
    {
        $body = $this->makeBody(
            content: [['type' => 'text', 'text' => 'Hello']],
            stopReason: 'end_turn',
            usage: [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'iterations' => [['input_tokens' => 80000, 'output_tokens' => 1200]],
            ],
        );

        $response = $this->parser->parseMessageResponse($body, []);

        $this->assertTrue($response->compactionDetected);
    }

    #[Test]
    public function does_not_detect_when_neither_signal_present(): void
    {
        $body = $this->makeBody(
            content: [['type' => 'text', 'text' => 'Hello']],
            stopReason: 'end_turn',
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
        );

        $response = $this->parser->parseMessageResponse($body, []);

        $this->assertFalse($response->compactionDetected);
    }

    #[Test]
    public function aggregates_iterations_input_tokens_into_total(): void
    {
        $usage = [
            'input_tokens' => 50,
            'output_tokens' => 200,
            'iterations' => [['input_tokens' => 70000, 'output_tokens' => 1000]],
        ];

        $usageData = $this->parser->extractUsageData($usage);

        $this->assertSame(70050, $usageData->totalInputTokens);
    }

    #[Test]
    public function aggregates_iterations_output_tokens_into_total(): void
    {
        $usage = [
            'input_tokens' => 50,
            'output_tokens' => 200,
            'iterations' => [['input_tokens' => 70000, 'output_tokens' => 1000]],
        ];

        $usageData = $this->parser->extractUsageData($usage);

        $this->assertSame(1200, $usageData->totalOutputTokens);
    }

    #[Test]
    public function stop_reason_compaction_literal_is_unknown_and_warned(): void
    {
        $body = $this->makeBody(
            content: [['type' => 'text', 'text' => 'Hello']],
            stopReason: 'compaction',
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
        );

        $response = $this->parser->parseMessageResponse($body, []);

        $warningCodes = array_column($response->warnings, 'code');
        $this->assertContains('parser.unknown_stop_reason', $warningCodes);
    }

    #[Test]
    public function memory_tool_use_block_is_collected_for_dispatch(): void
    {
        $body = $this->makeBody(
            content: [
                [
                    'type' => 'tool_use',
                    'id' => 'tu_1',
                    'name' => 'memory_20250818',
                    'input' => ['command' => 'view', 'path' => '/memories'],
                ],
            ],
            stopReason: 'tool_use',
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
        );

        $response = $this->parser->parseMessageResponse($body, []);

        $this->assertCount(1, $response->memoryToolUses);
        $this->assertSame('tu_1', $response->memoryToolUses[0]['id']);
    }

    #[Test]
    public function server_tool_use_web_search_count_extracted_from_usage(): void
    {
        $body = $this->makeBody(
            content: [['type' => 'text', 'text' => 'Search results']],
            stopReason: 'end_turn',
            usage: [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'server_tool_use' => ['web_search_requests' => 3],
            ],
        );

        $response = $this->parser->parseMessageResponse($body, []);

        $this->assertSame(3, $response->serverToolUseCounts['web_search']);
    }

    private function makeBody(array $content, string $stopReason, array $usage): array
    {
        return [
            'id' => 'msg_123',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => $usage,
        ];
    }
}
