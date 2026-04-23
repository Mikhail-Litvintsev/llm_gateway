<?php

namespace Tests\Unit\Enums;

use App\Components\ProviderGateway\Enums\ProviderName;
use App\Components\RequestPipeline\Enums\BlockFormat;
use App\Components\RequestPipeline\Enums\BlockRole;
use App\Components\RequestPipeline\Enums\BlockType;
use App\Components\RequestPipeline\Enums\RequestStatus;
use PHPUnit\Framework\TestCase;

class BlockTypeRoleCombinationsTest extends TestCase
{
    public function test_block_type_values(): void
    {
        $this->assertEquals('system', BlockType::System->value);
        $this->assertEquals('instruction', BlockType::Instruction->value);
        $this->assertEquals('description', BlockType::Description->value);
        $this->assertEquals('data', BlockType::Data->value);
        $this->assertEquals('history', BlockType::History->value);
        $this->assertEquals('history_tool_result', BlockType::HistoryToolResult->value);
        $this->assertEquals('prefix', BlockType::Prefix->value);
        $this->assertEquals('image', BlockType::Image->value);
    }

    public function test_block_role_values(): void
    {
        $this->assertEquals('system', BlockRole::System->value);
        $this->assertEquals('user', BlockRole::User->value);
        $this->assertEquals('assistant', BlockRole::Assistant->value);
        $this->assertEquals('tool', BlockRole::Tool->value);
    }

    public function test_block_format_values(): void
    {
        $this->assertEquals('text', BlockFormat::Text->value);
        $this->assertEquals('csv', BlockFormat::Csv->value);
        $this->assertEquals('json', BlockFormat::Json->value);
        $this->assertEquals('base64', BlockFormat::Base64->value);
    }

    public function test_request_status_values(): void
    {
        $this->assertEquals('accepted', RequestStatus::Accepted->value);
        $this->assertEquals('processing', RequestStatus::Processing->value);
        $this->assertEquals('completed', RequestStatus::Completed->value);
        $this->assertEquals('failed', RequestStatus::Failed->value);
        $this->assertEquals('timeout', RequestStatus::Timeout->value);
    }

    public function test_provider_name_from_model(): void
    {
        $this->assertEquals(ProviderName::Claude, ProviderName::fromModel('claude-sonnet-4-20250514'));
        $this->assertEquals(ProviderName::OpenAi, ProviderName::fromModel('gpt-4o'));
        $this->assertEquals(ProviderName::OpenAi, ProviderName::fromModel('o1-preview'));
        $this->assertEquals(ProviderName::DeepSeek, ProviderName::fromModel('deepseek-chat'));
        $this->assertEquals(ProviderName::Gemini, ProviderName::fromModel('gemini-pro'));
        $this->assertEquals(ProviderName::Mistral, ProviderName::fromModel('mistral-large'));
        $this->assertNull(ProviderName::fromModel('unknown-model'));
    }

    public function test_block_type_try_from_valid(): void
    {
        $this->assertNotNull(BlockType::tryFrom('system'));
        $this->assertNotNull(BlockType::tryFrom('data'));
        $this->assertNotNull(BlockType::tryFrom('history_tool_result'));
    }

    public function test_block_type_try_from_invalid(): void
    {
        $this->assertNull(BlockType::tryFrom('nonexistent'));
        $this->assertNull(BlockType::tryFrom(''));
    }
}
