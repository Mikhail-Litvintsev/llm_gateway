<?php

declare(strict_types=1);

namespace App\Components\Claude\Response;

use App\Components\Claude\DTO\MessageResponse;
use App\Components\Claude\DTO\UsageData;
use App\Components\Claude\ToolTypeCatalog;

final class ResponseParser
{
    private const array KNOWN_STOP_REASONS = [
        'end_turn', 'max_tokens', 'tool_use', 'pause_turn', 'refusal', 'model_context_window_exceeded',
    ];

    public function parseMessageResponse(array $body, array $headers): MessageResponse
    {
        $rateLimitHeaders = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);
            if (str_starts_with($lower, 'anthropic-ratelimit-')) {
                $rateLimitHeaders[$lower] = is_array($value) ? $value[0] : $value;
            }
        }

        $content = $body['content'] ?? [];
        $usage = $body['usage'] ?? [];
        $stopReason = $body['stop_reason'] ?? null;

        $warnings = [];
        $content = $this->tagMcpToolUses($content);
        $compactionDetected = $this->detectCompaction($content, $usage);
        $memoryToolUses = $this->collectMemoryToolUses($content);
        $citations = $this->extractCitations($content);
        $serverToolUseCounts = $this->extractServerToolUseCounts($usage);
        $serviceTierUsed = $usage['service_tier'] ?? null;
        $iterations = $usage['iterations'] ?? [];
        $thinkingTokens = $usage['thinking_tokens'] ?? 0;

        if ($stopReason !== null && ! in_array($stopReason, self::KNOWN_STOP_REASONS, true)) {
            $warnings[] = ['code' => 'parser.unknown_stop_reason', 'message' => "Unknown stop_reason: $stopReason"];
        }

        $this->collectBlockWarnings($content, $warnings);

        return new MessageResponse(
            anthropicId: $body['id'] ?? '',
            role: $body['role'] ?? 'assistant',
            content: $content,
            model: $body['model'] ?? '',
            stopReason: $stopReason,
            usage: $usage,
            anthropicRequestId: $this->headerValue($headers, 'request-id'),
            anthropicOrganizationId: $this->headerValue($headers, 'anthropic-organization-id'),
            rateLimitHeaders: $rateLimitHeaders,
            serviceTierUsed: $serviceTierUsed,
            compactionDetected: $compactionDetected,
            serverToolUseCounts: $serverToolUseCounts,
            thinkingTokens: $thinkingTokens,
            iterations: $iterations,
            memoryToolUses: $memoryToolUses,
            citations: $citations,
            warnings: $warnings,
        );
    }

    /** @return array{parsed: array, usage: array} */
    public function parseSuccess(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        return [
            'parsed' => $decoded,
            'usage' => $decoded['usage'] ?? [],
        ];
    }

    public function extractUsageData(array $usage): UsageData
    {
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cacheCreation5m = $this->extractCacheCreationTokens($usage, '5m');
        $cacheCreation1h = $this->extractCacheCreationTokens($usage, '1h');
        $cacheRead = $usage['cache_read_input_tokens'] ?? 0;
        $thinkingTokens = $usage['thinking_tokens'] ?? 0;
        $iterations = $usage['iterations'] ?? [];

        $totalInput = $inputTokens;
        $totalOutput = $outputTokens;
        $totalCacheCreation = $cacheCreation5m + $cacheCreation1h;
        $totalCacheRead = $cacheRead;

        foreach ($iterations as $iter) {
            $totalInput += $iter['input_tokens'] ?? 0;
            $totalOutput += $iter['output_tokens'] ?? 0;
            $totalCacheCreation += $iter['cache_creation_input_tokens'] ?? 0;
            $totalCacheRead += $iter['cache_read_input_tokens'] ?? 0;
        }

        return new UsageData(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreation5mTokens: $cacheCreation5m,
            cacheCreation1hTokens: $cacheCreation1h,
            cacheReadTokens: $cacheRead,
            thinkingTokens: $thinkingTokens,
            serverToolWebSearchCount: $this->countServerToolUse($usage, 'web_search'),
            serverToolWebFetchCount: $this->countServerToolUse($usage, 'web_fetch'),
            serverToolCodeExecCount: $this->countServerToolUse($usage, 'code_execution'),
            serverToolToolSearchCount: $this->countServerToolUse($usage, 'tool_search'),
            iterations: $iterations,
            totalInputTokens: $totalInput,
            totalOutputTokens: $totalOutput,
            totalCacheCreationTokens: $totalCacheCreation,
            totalCacheReadTokens: $totalCacheRead,
        );
    }

    private function tagMcpToolUses(array $content): array
    {
        foreach ($content as &$block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $name = $block['name'] ?? '';
            $separatorPos = strpos($name, '__');

            if ($separatorPos !== false && $separatorPos > 0) {
                $block['mcp_server_name'] = substr($name, 0, $separatorPos);
            }
        }

        return $content;
    }

    private function detectCompaction(array $content, array $usage): bool
    {
        if (array_any($content, fn (mixed $b): bool => is_array($b) && ($b['type'] ?? '') === 'compaction')) {
            return true;
        }

        return ! empty($usage['iterations'] ?? []);
    }

    private function collectMemoryToolUses(array $content): array
    {
        $memoryUses = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'tool_use' && ToolTypeCatalog::isMemoryTool($block['name'] ?? '')) {
                $memoryUses[] = $block;
            }
        }

        return $memoryUses;
    }

    private function extractCitations(array $content): array
    {
        $citations = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? '';
            if (! in_array($type, ['text', 'document', 'search_result'], true)) {
                continue;
            }

            foreach ($block['citations'] ?? [] as $citation) {
                if (is_array($citation)) {
                    $citations[] = $citation;
                }
            }
        }

        return $citations;
    }

    private function extractServerToolUseCounts(array $usage): array
    {
        $counts = [
            'web_search' => 0,
            'web_fetch' => 0,
            'code_execution' => 0,
            'tool_search' => 0,
        ];

        $serverToolUse = $usage['server_tool_use'] ?? [];

        if (is_array($serverToolUse)) {
            $counts['web_search'] = $serverToolUse['web_search_requests'] ?? 0;
            $counts['web_fetch'] = $serverToolUse['web_fetch_requests'] ?? 0;
            $counts['code_execution'] = $serverToolUse['code_execution_requests'] ?? 0;
            $counts['tool_search'] = $serverToolUse['tool_search_requests'] ?? 0;
        }

        return $counts;
    }

    private function collectBlockWarnings(array $content, array &$warnings): void
    {
        $knownTypes = [
            'text', 'thinking', 'redacted_thinking', 'compaction', 'tool_use', 'server_tool_use',
            'document', 'search_result',
            'web_search_tool_result', 'web_fetch_tool_result', 'code_execution_tool_result',
            'bash_code_execution_tool_result', 'text_editor_code_execution_tool_result',
            'tool_search_tool_regex_tool_result', 'tool_search_tool_bm25_tool_result',
        ];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? '';

            if ($type !== '' && ! in_array($type, $knownTypes, true)) {
                $warnings[] = ['code' => 'parser.unknown_block_type', 'message' => "Unknown content block type: $type"];
            }

            if ($type === 'thinking' && ! isset($block['signature'])) {
                $warnings[] = ['code' => 'parser.thinking_missing_signature', 'message' => 'Thinking block missing signature'];
            }
        }
    }

    private function extractCacheCreationTokens(array $usage, string $ttl): int
    {
        $breakpoints = $usage['cache_creation_input_tokens_breakdown'] ?? [];

        foreach ($breakpoints as $bp) {
            if (($bp['ttl'] ?? '') === $ttl) {
                return $bp['tokens'] ?? 0;
            }
        }

        if ($ttl === '5m' && isset($usage['cache_creation_input_tokens']) && empty($breakpoints)) {
            return $usage['cache_creation_input_tokens'];
        }

        return 0;
    }

    private function countServerToolUse(array $usage, string $toolType): int
    {
        $serverToolUse = $usage['server_tool_use'] ?? [];

        foreach ($serverToolUse as $entry) {
            if (($entry['type'] ?? '') === $toolType) {
                return $entry['count'] ?? 0;
            }
        }

        return 0;
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? $value[0] : (string) $value;
            }
        }

        return null;
    }
}
