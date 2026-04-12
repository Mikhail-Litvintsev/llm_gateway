<?php

declare(strict_types=1);

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocsFeatureCoverageTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const array FEATURE_KEYWORDS = [
        'sync_messages' => ['sync', 'messages', 'POST /api/v1/messages'],
        'sync_streaming' => ['streaming', 'SSE', 'stream'],
        'async_webhook' => ['async', 'webhook', 'async_callback'],
        'prompt_caching_manual' => ['cache_control', 'breakpoint', 'cache'],
        'prompt_caching_auto' => ['auto', 'cache', 'AutoCacheInjector'],
        'batch_immediate' => ['batch', 'batches'],
        'batch_accumulator' => ['accumulator', 'batch'],
        'token_counting' => ['count_tokens', 'token'],
        'files_api' => ['files', 'upload', 'file_id'],
        'sessions' => ['session', 'multi-turn'],
        'compaction' => ['compaction', 'compact'],
        'context_editing' => ['context', 'editing'],
        'adaptive_thinking' => ['adaptive', 'thinking', 'effort'],
        'manual_thinking' => ['thinking', 'budget_tokens'],
        'vision' => ['vision', 'image', 'base64'],
        'pdf_documents' => ['pdf', 'PDF'],
        'citations' => ['citation'],
        'search_result_blocks' => ['search_result', 'RAG'],
        'web_search' => ['web_search'],
        'web_fetch' => ['web_fetch'],
        'code_execution' => ['code_execution'],
        'bash_tool' => ['bash'],
        'text_editor_tool' => ['text_editor'],
        'computer_use' => ['computer_use'],
        'custom_tools' => ['custom tool', 'tool_use', 'function'],
        'structured_outputs' => ['structured', 'output_config'],
        'mcp_connector' => ['MCP', 'mcp'],
        'skills' => ['skill', 'xlsx', 'docx'],
        'inference_geo' => ['inference', 'geo'],
        'service_tier' => ['service_tier', 'tier'],
        'fast_mode' => ['fast_mode', 'fast mode'],
    ];

    #[Test]
    public function features_covered_in_claude_md(): void
    {
        $content = file_get_contents(base_path('CLAUDE.md'));
        $this->assertFeaturesCovered($content, 'CLAUDE.md');
    }

    #[Test]
    public function features_covered_in_client_guide(): void
    {
        $content = file_get_contents(base_path('documentation/client_integration_guide.md'));
        $this->assertFeaturesCovered($content, 'client_integration_guide.md');
    }

    #[Test]
    public function features_covered_in_internal_logic(): void
    {
        $content = file_get_contents(base_path('documentation/internal_logic.md'));
        $this->assertFeaturesCovered($content, 'internal_logic.md');
    }

    private function assertFeaturesCovered(string $content, string $docName): void
    {
        $missing = [];

        foreach (self::FEATURE_KEYWORDS as $feature => $keywords) {
            $found = false;
            foreach ($keywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $feature;
            }
        }

        $this->assertEmpty(
            $missing,
            "Features missing from $docName:\n" . implode("\n", $missing)
        );
    }
}
