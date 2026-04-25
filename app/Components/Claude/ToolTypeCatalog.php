<?php

declare(strict_types=1);

namespace App\Components\Claude;

use App\Components\Claude\Enums\ServerToolFeature;

final class ToolTypeCatalog
{
    public const string WEB_SEARCH = 'web_search_20260209';

    public const string WEB_FETCH = 'web_fetch_20260209';

    public const string CODE_EXECUTION = 'code_execution_20260120';

    public const string TOOL_SEARCH_REGEX = 'tool_search_tool_regex_20251119';

    public const string TOOL_SEARCH_BM25 = 'tool_search_tool_bm25_20251119';

    public const string MEMORY = 'memory_20250818';

    public const string BASH = 'bash_20250124';

    public const string TEXT_EDITOR = 'text_editor_20250728';

    public const string COMPUTER = 'computer_20250124';

    public const string EDIT_COMPACT = 'compact_20260112';

    public const string EDIT_CLEAR_TOOL_USES = 'clear_tool_uses_20250919';

    public const string EDIT_CLEAR_THINKING = 'clear_thinking_20251015';

    public const string BETA_COMPACT = 'compact-2026-01-12';

    public const string BETA_CONTEXT_MANAGEMENT = 'context-management-2025-06-27';

    public const string BETA_COMPUTER_USE = 'computer-use-2025-01-24';

    private const array FEATURE_MAP = [
        self::WEB_SEARCH => ServerToolFeature::WebSearch,
        self::WEB_FETCH => ServerToolFeature::WebFetch,
        self::CODE_EXECUTION => ServerToolFeature::CodeExecution,
        self::TOOL_SEARCH_REGEX => ServerToolFeature::ToolSearch,
        self::TOOL_SEARCH_BM25 => ServerToolFeature::ToolSearch,
        self::MEMORY => ServerToolFeature::Memory,
        self::BASH => ServerToolFeature::Bash,
        self::TEXT_EDITOR => ServerToolFeature::TextEditor,
        self::COMPUTER => ServerToolFeature::ComputerUse,
    ];

    /** @return string[] */
    public static function allServerToolTypes(): array
    {
        return [
            self::WEB_SEARCH,
            self::WEB_FETCH,
            self::CODE_EXECUTION,
            self::TOOL_SEARCH_REGEX,
            self::TOOL_SEARCH_BM25,
            self::MEMORY,
            self::BASH,
            self::TEXT_EDITOR,
            self::COMPUTER,
        ];
    }

    public static function featureFor(string $type): ?ServerToolFeature
    {
        return self::FEATURE_MAP[$type] ?? null;
    }

    public static function isMemoryTool(string $type): bool
    {
        return $type === self::MEMORY;
    }

    public static function isToolSearch(string $type): bool
    {
        return $type === self::TOOL_SEARCH_REGEX || $type === self::TOOL_SEARCH_BM25;
    }

    public static function isContextManagementEdit(string $type): bool
    {
        return $type === self::EDIT_COMPACT
            || $type === self::EDIT_CLEAR_TOOL_USES
            || $type === self::EDIT_CLEAR_THINKING;
    }

    /**
     * @param  list<string>  $requestToolTypes
     */
    public static function codeExecutionIsFree(array $requestToolTypes): bool
    {
        if (! in_array(self::CODE_EXECUTION, $requestToolTypes, true)) {
            return false;
        }

        return in_array(self::WEB_SEARCH, $requestToolTypes, true)
            || in_array(self::WEB_FETCH, $requestToolTypes, true);
    }
}
