<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Batch;

use App\Components\Claude\Batch\BatchResultParser;
use App\Components\Claude\DTO\ResultLine;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('phase3-unit')]
final class BatchResultParserTest extends TestCase
{
    private BatchResultParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BatchResultParser();
    }

    #[Test]
    public function succeeded_line_parsed_correctly(): void
    {
        $json = json_encode([
            'custom_id' => 'a',
            'result' => [
                'type' => 'succeeded',
                'message' => [
                    'id' => 'msg_1',
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ],
        ]);

        $result = $this->parser->parseLine($json);

        $this->assertInstanceOf(ResultLine::class, $result);
        $this->assertSame('a', $result->customId);
        $this->assertSame('succeeded', $result->type);
        $this->assertSame('msg_1', $result->message['id']);
        $this->assertSame(10, $result->message['usage']['input_tokens']);
        $this->assertNull($result->error);
    }

    #[Test]
    public function errored_line_parsed_correctly(): void
    {
        $json = json_encode([
            'custom_id' => 'b',
            'result' => [
                'type' => 'errored',
                'error' => ['type' => 'invalid_request', 'message' => 'bad'],
            ],
        ]);

        $result = $this->parser->parseLine($json);

        $this->assertSame('b', $result->customId);
        $this->assertSame('errored', $result->type);
        $this->assertSame('invalid_request', $result->error['type']);
        $this->assertNull($result->message);
    }

    #[Test]
    public function canceled_line_parsed_correctly(): void
    {
        $json = json_encode([
            'custom_id' => 'c',
            'result' => ['type' => 'canceled'],
        ]);

        $result = $this->parser->parseLine($json);

        $this->assertSame('c', $result->customId);
        $this->assertSame('canceled', $result->type);
        $this->assertNull($result->message);
        $this->assertNull($result->error);
    }

    #[Test]
    public function expired_line_parsed_correctly(): void
    {
        $json = json_encode([
            'custom_id' => 'd',
            'result' => ['type' => 'expired'],
        ]);

        $result = $this->parser->parseLine($json);

        $this->assertSame('d', $result->customId);
        $this->assertSame('expired', $result->type);
        $this->assertNull($result->message);
        $this->assertNull($result->error);
    }

    #[Test]
    public function blank_lines_return_null(): void
    {
        $this->assertNull($this->parser->parseLine(''));
        $this->assertNull($this->parser->parseLine('   '));
        $this->assertNull($this->parser->parseLine("\n"));
    }

    #[Test]
    public function unknown_result_type_throws(): void
    {
        $json = json_encode([
            'custom_id' => 'x',
            'result' => ['type' => 'unknown_status'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown result type: unknown_status');

        $this->parser->parseLine($json);
    }

    #[Test]
    public function missing_custom_id_throws(): void
    {
        $json = json_encode([
            'result' => ['type' => 'succeeded'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing custom_id or result.type');

        $this->parser->parseLine($json);
    }

    #[Test]
    public function missing_result_type_throws(): void
    {
        $json = json_encode([
            'custom_id' => 'z',
            'result' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing custom_id or result.type');

        $this->parser->parseLine($json);
    }
}
