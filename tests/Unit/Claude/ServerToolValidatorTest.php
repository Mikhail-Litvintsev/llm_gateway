<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\ToolTypeCatalog;
use App\Components\Validation\Rules\CitationsConsistencyRule;
use App\Components\Validation\Rules\MemoryModelGateRule;
use App\Components\Validation\Rules\PtcContractRule;
use App\Components\Validation\Rules\SearchResultBlockRule;
use App\Components\Validation\Rules\ThinkingCompatibilityRule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServerToolValidatorTest extends TestCase
{
    #[Test]
    public function memory_on_opus_rejected(): void
    {
        $rule = new MemoryModelGateRule();

        $error = $rule->check([
            'model' => 'claude-opus',
            'tools' => [['type' => ToolTypeCatalog::MEMORY]],
        ]);

        $this->assertNotNull($error);
        $this->assertSame('memory_model_gate', $error->code);
    }

    #[Test]
    public function memory_on_sonnet_accepted(): void
    {
        $rule = new MemoryModelGateRule();

        $error = $rule->check([
            'model' => 'claude-sonnet',
            'tools' => [['type' => ToolTypeCatalog::MEMORY]],
        ]);

        $this->assertNull($error);
    }

    #[Test]
    public function search_result_missing_source_rejected(): void
    {
        $rule = new SearchResultBlockRule();

        $error = $rule->check([
            'messages' => [
                [
                    'content' => [
                        [
                            'type' => 'search_result',
                            'title' => 'Test',
                            'content' => [['type' => 'text', 'text' => 'body']],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertNotNull($error);
        $this->assertSame('missing_search_result_key', $error->code);
    }

    #[Test]
    public function citations_all_or_nothing_enforced(): void
    {
        $rule = new CitationsConsistencyRule();

        $error = $rule->check([
            'messages' => [
                [
                    'content' => [
                        ['type' => 'document', 'citations' => ['enabled' => true]],
                        ['type' => 'document', 'citations' => ['enabled' => false]],
                    ],
                ],
            ],
        ]);

        $this->assertNotNull($error);
        $this->assertSame('citations_all_or_nothing', $error->code);
    }

    #[Test]
    public function citations_with_output_config_rejected(): void
    {
        $rule = new CitationsConsistencyRule();

        $error = $rule->check([
            'messages' => [
                [
                    'content' => [
                        ['type' => 'document', 'citations' => ['enabled' => true]],
                    ],
                ],
            ],
            'output_config' => ['format' => 'json'],
        ]);

        $this->assertNotNull($error);
        $this->assertSame('citations_vs_output_config', $error->code);
    }

    #[Test]
    public function ptc_with_strict_rejected(): void
    {
        $rule = new PtcContractRule();

        $error = $rule->check([
            'tools' => [
                ['type' => 'function', 'allowed_callers' => [ToolTypeCatalog::CODE_EXECUTION]],
                ['type' => 'function', 'strict' => true],
            ],
        ]);

        $this->assertNotNull($error);
        $this->assertSame('ptc_strict', $error->code);
    }

    #[Test]
    public function ptc_with_disable_parallel_rejected(): void
    {
        $rule = new PtcContractRule();

        $error = $rule->check([
            'tools' => [
                ['type' => 'function', 'allowed_callers' => [ToolTypeCatalog::CODE_EXECUTION]],
            ],
            'disable_parallel_tool_use' => true,
        ]);

        $this->assertNotNull($error);
        $this->assertSame('ptc_disable_parallel', $error->code);
    }

    #[Test]
    public function thinking_adaptive_on_haiku_rejected(): void
    {
        config([
            'llm.claude.model_capabilities.claude-haiku' => [
                'supports_adaptive_thinking' => false,
                'supports_thinking' => true,
            ],
        ]);

        $rule = new ThinkingCompatibilityRule();

        $error = $rule->check([
            'model' => 'claude-haiku',
            'thinking' => ['type' => 'adaptive'],
        ]);

        $this->assertNotNull($error);
        $this->assertSame('adaptive_not_supported', $error->code);
    }

    #[Test]
    public function thinking_with_top_p_below_0_95_rejected(): void
    {
        config([
            'llm.claude.model_capabilities.claude-sonnet' => [
                'supports_adaptive_thinking' => true,
                'supports_thinking' => true,
            ],
        ]);

        $rule = new ThinkingCompatibilityRule();

        $error = $rule->check([
            'model' => 'claude-sonnet',
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1000],
            'max_tokens' => 5000,
            'top_p' => 0.8,
        ]);

        $this->assertNotNull($error);
        $this->assertSame('top_p_with_thinking', $error->code);
    }
}
