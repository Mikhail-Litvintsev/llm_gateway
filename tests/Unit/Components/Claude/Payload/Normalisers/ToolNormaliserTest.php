<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Normalisers;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Normalisers\ToolNormaliser;
use App\Components\Claude\ToolTypeCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ToolNormaliserTest extends TestCase
{
    private ToolNormaliser $normaliser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normaliser = new ToolNormaliser;
    }

    #[Test]
    public function normalises_web_search_with_allowed_domains(): void
    {
        [$tools, $serverTypes, $hasPtc] = $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::WEB_SEARCH,
            'name' => 'web_search',
            'allowed_domains' => ['example.com'],
            'max_uses' => 3,
        ]]);

        $this->assertSame(['example.com'], $tools[0]['allowed_domains']);
        $this->assertContains(ToolTypeCatalog::WEB_SEARCH, $serverTypes);
        $this->assertFalse($hasPtc);
    }

    #[Test]
    public function rejects_web_search_with_both_allowed_and_blocked_domains(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('web_search: allowed_domains and blocked_domains cannot be combined');

        $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::WEB_SEARCH,
            'name' => 'web_search',
            'allowed_domains' => ['example.com'],
            'blocked_domains' => ['evil.com'],
        ]]);
    }

    #[Test]
    public function rejects_web_search_unknown_option(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessageMatches("/Unknown option 'unsupported' on server tool/");

        $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::WEB_SEARCH,
            'name' => 'web_search',
            'unsupported' => true,
        ]]);
    }

    #[Test]
    public function normalises_web_fetch_with_citations(): void
    {
        [$tools] = $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::WEB_FETCH,
            'name' => 'web_fetch',
            'citations' => ['enabled' => true],
        ]]);

        $this->assertSame(['enabled' => true], $tools[0]['citations']);
    }

    #[Test]
    public function rejects_web_fetch_invalid_citations_shape(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('web_fetch: citations must be {enabled: bool}');

        $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::WEB_FETCH,
            'name' => 'web_fetch',
            'citations' => ['nope' => true],
        ]]);
    }

    #[Test]
    public function normalises_code_execution(): void
    {
        [$tools, $types] = $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::CODE_EXECUTION,
            'name' => 'code_execution',
        ]]);

        $this->assertSame(ToolTypeCatalog::CODE_EXECUTION, $tools[0]['type']);
        $this->assertContains(ToolTypeCatalog::CODE_EXECUTION, $types);
    }

    #[Test]
    public function normalises_tool_search_variants(): void
    {
        [$tools] = $this->normaliser->normalise([
            ['type' => ToolTypeCatalog::TOOL_SEARCH_REGEX, 'name' => 'r', 'max_results' => 5],
            ['type' => ToolTypeCatalog::TOOL_SEARCH_BM25, 'name' => 'b', 'max_results' => 10],
        ]);

        $this->assertCount(2, $tools);
        $this->assertSame(5, $tools[0]['max_results']);
        $this->assertSame(10, $tools[1]['max_results']);
    }

    #[Test]
    public function normalises_memory(): void
    {
        [$tools] = $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::MEMORY,
            'name' => 'memory',
        ]]);

        $this->assertSame(ToolTypeCatalog::MEMORY, $tools[0]['type']);
    }

    #[Test]
    public function deduplicates_repeated_memory_tool_in_server_types(): void
    {
        [, $serverTypes] = $this->normaliser->normalise([
            ['type' => ToolTypeCatalog::MEMORY, 'name' => 'memory'],
            ['type' => ToolTypeCatalog::MEMORY, 'name' => 'memory'],
        ]);

        $this->assertSame([ToolTypeCatalog::MEMORY], $serverTypes);
    }

    #[Test]
    public function normalises_bash_text_editor(): void
    {
        [$tools] = $this->normaliser->normalise([
            ['type' => ToolTypeCatalog::BASH, 'name' => 'bash'],
            ['type' => ToolTypeCatalog::TEXT_EDITOR, 'name' => 'editor'],
        ]);

        $this->assertSame(ToolTypeCatalog::BASH, $tools[0]['type']);
        $this->assertSame(ToolTypeCatalog::TEXT_EDITOR, $tools[1]['type']);
    }

    #[Test]
    public function normalises_computer_with_display_size(): void
    {
        [$tools] = $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::COMPUTER,
            'name' => 'computer',
            'display_width_px' => 1024,
            'display_height_px' => 768,
            'display_number' => 2,
        ]]);

        $this->assertSame(1024, $tools[0]['display_width_px']);
        $this->assertSame(2, $tools[0]['display_number']);
    }

    #[Test]
    public function rejects_computer_without_display_size(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('computer: display_width_px and display_height_px are required');

        $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::COMPUTER,
            'name' => 'computer',
        ]]);
    }

    #[Test]
    public function applies_default_display_number_for_computer(): void
    {
        [$tools] = $this->normaliser->normalise([[
            'type' => ToolTypeCatalog::COMPUTER,
            'name' => 'computer',
            'display_width_px' => 800,
            'display_height_px' => 600,
        ]]);

        $this->assertSame(1, $tools[0]['display_number']);
    }

    #[Test]
    public function passes_through_custom_tool_without_ptc(): void
    {
        $tool = ['name' => 'my_tool', 'description' => 'd', 'input_schema' => ['type' => 'object']];

        [$tools, $types, $hasPtc] = $this->normaliser->normalise([$tool]);

        $this->assertSame($tool, $tools[0]);
        $this->assertSame([], $types);
        $this->assertFalse($hasPtc);
    }

    #[Test]
    public function normalises_custom_tool_with_ptc_allowed_callers(): void
    {
        [$tools, , $hasPtc] = $this->normaliser->normalise([
            [
                'type' => ToolTypeCatalog::CODE_EXECUTION,
                'name' => 'code_execution',
            ],
            [
                'name' => 'my_tool',
                'description' => 'd',
                'input_schema' => ['type' => 'object'],
                'allowed_callers' => ['direct', ToolTypeCatalog::CODE_EXECUTION],
            ],
        ]);

        $this->assertTrue($hasPtc);
        $this->assertSame(['direct', ToolTypeCatalog::CODE_EXECUTION], $tools[1]['allowed_callers']);
    }

    #[Test]
    public function rejects_ptc_with_strict_true(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('PTC is incompatible with strict: true');

        $this->normaliser->normalise([[
            'name' => 'my_tool',
            'allowed_callers' => ['direct'],
            'strict' => true,
        ]]);
    }

    #[Test]
    public function rejects_ptc_with_code_execution_caller_but_no_code_execution_tool(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('allowed_callers references code_execution but code_execution tool is absent');

        $this->normaliser->normalise([[
            'name' => 'my_tool',
            'allowed_callers' => [ToolTypeCatalog::CODE_EXECUTION],
        ]]);
    }

    #[Test]
    public function rejects_custom_tool_beyond_max_cap(): void
    {
        config()->set('llm.claude.max_custom_tools', 2);

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessageMatches('/Too many custom tools \(3\), maximum is 2/');

        $tools = [];
        for ($i = 0; $i < 3; $i++) {
            $tools[] = ['name' => "tool_$i", 'description' => 'd', 'input_schema' => ['type' => 'object']];
        }

        $this->normaliser->normalise($tools);
    }

    #[Test]
    public function returns_has_tool_search_true_when_tool_search_present(): void
    {
        config()->set('llm.claude.max_custom_tools', 5);

        $tools = [['type' => ToolTypeCatalog::TOOL_SEARCH_REGEX, 'name' => 'r']];
        for ($i = 0; $i < 100; $i++) {
            $tools[] = ['name' => "tool_$i", 'description' => 'd', 'input_schema' => ['type' => 'object']];
        }

        [, $types] = $this->normaliser->normalise($tools);

        $this->assertContains(ToolTypeCatalog::TOOL_SEARCH_REGEX, $types);
    }
}
