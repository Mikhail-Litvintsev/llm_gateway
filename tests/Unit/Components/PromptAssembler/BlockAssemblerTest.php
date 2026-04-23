<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\BlockAssembler;
use App\Components\PromptAssembler\DataBlockFormatter;
use App\Components\RequestPipeline\DTO\PromptBlock;
use PHPUnit\Framework\TestCase;

class BlockAssemblerTest extends TestCase
{
    private BlockAssembler $assembler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assembler = new BlockAssembler(new DataBlockFormatter());
    }

    public function test_assembles_system_prompt(): void
    {
        $blocks = [
            new PromptBlock('system', 'system', null, null, null, null, null, null, false, 'You are helpful.'),
            new PromptBlock('data', 'user', null, null, null, null, null, null, false, 'Hello'),
        ];

        $result = $this->assembler->assemble($blocks, 'claude');

        $this->assertEquals('You are helpful.', $result->systemPrompt);
    }

    public function test_assembles_user_message(): void
    {
        $blocks = [
            new PromptBlock('data', 'user', null, null, null, null, null, null, false, 'Hello'),
        ];

        $result = $this->assembler->assemble($blocks, 'claude');

        $this->assertNotEmpty($result->messages);
        $this->assertEquals('user', $result->messages[0]['role']);
    }

    public function test_assembles_history_messages_in_order(): void
    {
        $blocks = [
            new PromptBlock('history', 'user', null, null, null, null, null, null, false, 'Hi'),
            new PromptBlock('history', 'assistant', null, null, null, null, null, null, false, 'Hello!'),
            new PromptBlock('data', 'user', null, null, null, null, null, null, false, 'Now help me.'),
        ];

        $result = $this->assembler->assemble($blocks, 'claude');

        $this->assertCount(3, $result->messages);
        $this->assertEquals('user', $result->messages[0]['role']);
        $this->assertEquals('Hi', $result->messages[0]['content']);
        $this->assertEquals('assistant', $result->messages[1]['role']);
    }

    public function test_assembles_prefix_as_assistant_message(): void
    {
        $blocks = [
            new PromptBlock('instruction', 'user', null, null, null, null, null, null, false, 'Write JSON'),
            new PromptBlock('prefix', 'assistant', null, null, null, null, null, null, false, '{"result":'),
        ];

        $result = $this->assembler->assemble($blocks, 'claude');

        $messages = $result->messages;
        $lastMessage = $messages[array_key_last($messages)];
        $this->assertEquals('assistant', $lastMessage['role']);
        $this->assertEquals('{"result":', $lastMessage['content']);
    }

    public function test_image_block_formats_for_claude(): void
    {
        $blocks = [
            new PromptBlock('data', 'user', null, null, null, null, null, null, false, 'Describe this'),
            new PromptBlock('image', 'user', null, null, 'base64', 'image/png', null, null, false, 'base64data'),
        ];

        $result = $this->assembler->assemble($blocks, 'claude');

        $messages = $result->messages;
        $userMessage = $messages[array_key_last($messages)];
        $this->assertIsArray($userMessage['content']);
        $imagePart = $userMessage['content'][1];
        $this->assertEquals('image', $imagePart['type']);
        $this->assertEquals('base64', $imagePart['source']['type']);
    }

    public function test_image_block_formats_for_openai(): void
    {
        $blocks = [
            new PromptBlock('data', 'user', null, null, null, null, null, null, false, 'Describe this'),
            new PromptBlock('image', 'user', null, null, 'base64', 'image/png', null, null, false, 'base64data'),
        ];

        $result = $this->assembler->assemble($blocks, 'openai');

        $messages = $result->messages;
        $userMessage = $messages[array_key_last($messages)];
        $this->assertIsArray($userMessage['content']);
        $imagePart = $userMessage['content'][1];
        $this->assertEquals('image_url', $imagePart['type']);
    }
}
