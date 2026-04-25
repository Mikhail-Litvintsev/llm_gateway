<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Normalisers;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Normalisers\MessageContentNormaliser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MessageContentNormaliserTest extends TestCase
{
    private MessageContentNormaliser $normaliser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normaliser = new MessageContentNormaliser;
    }

    #[Test]
    public function pass_through_when_no_search_result_blocks(): void
    {
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ];

        $this->assertSame($messages, $this->normaliser->normalise($messages));
    }

    #[Test]
    public function normalises_valid_search_result_block(): void
    {
        $block = [
            'type' => 'search_result',
            'title' => 'doc',
            'source' => 'https://example.com',
            'content' => [['type' => 'text', 'text' => 'hello']],
            'citations' => ['enabled' => true],
        ];
        $messages = [['role' => 'user', 'content' => [$block]]];

        $result = $this->normaliser->normalise($messages);

        $this->assertSame($block, $result[0]['content'][0]);
    }

    #[Test]
    public function throws_on_unknown_key_in_search_result_block(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Unknown key on search_result block: extra');

        $this->normaliser->normalise([
            ['role' => 'user', 'content' => [[
                'type' => 'search_result',
                'title' => 't',
                'source' => 's',
                'content' => [['type' => 'text', 'text' => 'x']],
                'extra' => 'oops',
            ]]],
        ]);
    }

    #[Test]
    public function throws_on_missing_required_key(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('search_result block missing required key: source');

        $this->normaliser->normalise([
            ['role' => 'user', 'content' => [[
                'type' => 'search_result',
                'title' => 't',
                'content' => [['type' => 'text', 'text' => 'x']],
            ]]],
        ]);
    }

    #[Test]
    public function throws_on_non_text_inner_block(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('search_result.content only accepts text blocks');

        $this->normaliser->normalise([
            ['role' => 'user', 'content' => [[
                'type' => 'search_result',
                'title' => 't',
                'source' => 's',
                'content' => [['type' => 'image', 'source' => 'x']],
            ]]],
        ]);
    }

    #[Test]
    public function throws_on_invalid_citations_shape(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('search_result: citations must be {enabled: bool}');

        $this->normaliser->normalise([
            ['role' => 'user', 'content' => [[
                'type' => 'search_result',
                'title' => 't',
                'source' => 's',
                'content' => [['type' => 'text', 'text' => 'x']],
                'citations' => ['something' => true],
            ]]],
        ]);
    }
}
