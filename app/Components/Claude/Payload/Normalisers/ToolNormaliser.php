<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Normalisers;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\ToolTypeCatalog;

final readonly class ToolNormaliser
{
    private const array SERVER_TOOL_TYPES = [
        ToolTypeCatalog::WEB_SEARCH,
        ToolTypeCatalog::WEB_FETCH,
        ToolTypeCatalog::CODE_EXECUTION,
        ToolTypeCatalog::TOOL_SEARCH_REGEX,
        ToolTypeCatalog::TOOL_SEARCH_BM25,
        ToolTypeCatalog::MEMORY,
        ToolTypeCatalog::BASH,
        ToolTypeCatalog::TEXT_EDITOR,
        ToolTypeCatalog::COMPUTER,
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rawTools
     * @return array{0: array<int, array<string, mixed>>, 1: list<string>, 2: bool} [normalisedTools, serverToolTypes, hasPtcTool]
     *
     * @throws PayloadBuildException
     */
    public function normalise(array $rawTools): array
    {
        $serverToolTypes = [];
        $hasToolSearch = false;

        foreach ($rawTools as $tool) {
            $type = $tool['type'] ?? null;
            if (is_string($type) && in_array($type, self::SERVER_TOOL_TYPES, true)) {
                if (! in_array($type, $serverToolTypes, true)) {
                    $serverToolTypes[] = $type;
                }
                if (ToolTypeCatalog::isToolSearch($type)) {
                    $hasToolSearch = true;
                }
            }
        }

        $hasCodeExecution = in_array(ToolTypeCatalog::CODE_EXECUTION, $serverToolTypes, true);
        $hasPtcTool = false;
        $normalised = [];

        foreach ($rawTools as $tool) {
            $type = $tool['type'] ?? null;

            if (is_string($type) && in_array($type, self::SERVER_TOOL_TYPES, true)) {
                $normalised[] = $this->normaliseServerTool($type, $tool);
            } else {
                $norm = $this->normaliseCustomTool($tool, $hasCodeExecution);
                if (isset($norm['allowed_callers'])) {
                    $hasPtcTool = true;
                }
                $normalised[] = $norm;
            }
        }

        $this->enforceMemoryUniqueness($serverToolTypes);

        $customCount = count($normalised) - count($serverToolTypes);
        $customCap = $hasToolSearch ? 10_000 : (int) config('llm.claude.max_custom_tools', 128);

        if ($customCount > $customCap) {
            throw PayloadBuildException::invalidRequest(
                "Too many custom tools ($customCount), maximum is $customCap"
            );
        }

        return [$normalised, $serverToolTypes, $hasPtcTool];
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseServerTool(string $type, array $tool): array
    {
        return match ($type) {
            ToolTypeCatalog::WEB_SEARCH => $this->normaliseWebSearch($tool),
            ToolTypeCatalog::WEB_FETCH => $this->normaliseWebFetch($tool),
            ToolTypeCatalog::CODE_EXECUTION => $this->normaliseCodeExecution($tool),
            ToolTypeCatalog::TOOL_SEARCH_REGEX,
            ToolTypeCatalog::TOOL_SEARCH_BM25 => $this->normaliseToolSearch($tool),
            ToolTypeCatalog::MEMORY => $this->normaliseMemory($tool),
            ToolTypeCatalog::BASH => $this->normaliseBash($tool),
            ToolTypeCatalog::TEXT_EDITOR => $this->normaliseTextEditor($tool),
            ToolTypeCatalog::COMPUTER => $this->normaliseComputer($tool),
            default => throw PayloadBuildException::invalidRequest("Unsupported server tool type: $type"),
        };
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseWebSearch(array $tool): array
    {
        $allowed = ['type', 'name', 'max_uses', 'allowed_domains', 'blocked_domains', 'user_location'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        if (isset($tool['allowed_domains'], $tool['blocked_domains'])) {
            throw PayloadBuildException::invalidRequest(
                'web_search: allowed_domains and blocked_domains cannot be combined'
            );
        }

        return $this->pickKeys($tool, $allowed);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseWebFetch(array $tool): array
    {
        $allowed = ['type', 'name', 'max_content_tokens', 'citations'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        if (isset($tool['citations']) && (! is_array($tool['citations']) || ! array_key_exists('enabled', $tool['citations']))) {
            throw PayloadBuildException::invalidRequest(
                'web_fetch: citations must be {enabled: bool}'
            );
        }

        return $this->pickKeys($tool, $allowed);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseCodeExecution(array $tool): array
    {
        $allowed = ['type', 'name', 'container'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseToolSearch(array $tool): array
    {
        $allowed = ['type', 'name', 'max_results'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseMemory(array $tool): array
    {
        $allowed = ['type', 'name'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseBash(array $tool): array
    {
        $allowed = ['type', 'name'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseTextEditor(array $tool): array
    {
        $allowed = ['type', 'name'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        return $this->pickKeys($tool, $allowed);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseComputer(array $tool): array
    {
        $allowed = ['type', 'name', 'display_width_px', 'display_height_px', 'display_number'];
        $this->rejectUnknownKeys($tool, $allowed, (string) $tool['type']);

        if (! isset($tool['display_width_px'], $tool['display_height_px'])) {
            throw PayloadBuildException::invalidRequest(
                'computer: display_width_px and display_height_px are required'
            );
        }

        $result = $this->pickKeys($tool, $allowed);
        $result['display_number'] ??= 1;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseCustomTool(array $tool, bool $hasCodeExecution): array
    {
        if (! isset($tool['allowed_callers'])) {
            return $tool;
        }

        return $this->normaliseCustomToolWithPtc($tool, $hasCodeExecution);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normaliseCustomToolWithPtc(array $tool, bool $hasCodeExecution): array
    {
        $callers = $tool['allowed_callers'];

        if (! is_array($callers) || $callers === []) {
            throw PayloadBuildException::invalidRequest('allowed_callers must contain at least one entry');
        }

        $validValues = ['direct', ToolTypeCatalog::CODE_EXECUTION];
        $normalized = [];

        foreach ($callers as $v) {
            if (! in_array($v, $validValues, true)) {
                throw PayloadBuildException::invalidRequest("Invalid allowed_callers value: $v");
            }
            $normalized[$v] = true;
        }

        $tool['allowed_callers'] = array_keys($normalized);

        if (isset($normalized[ToolTypeCatalog::CODE_EXECUTION]) && ! $hasCodeExecution) {
            throw PayloadBuildException::invalidRequest(
                'allowed_callers references code_execution but code_execution tool is absent'
            );
        }

        if (($tool['strict'] ?? false) === true) {
            throw PayloadBuildException::invalidRequest('PTC is incompatible with strict: true');
        }

        return $tool;
    }

    /**
     * @param  array<string, mixed>  $tool
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $tool, array $allowed, string $type): void
    {
        $unknown = array_diff(array_keys($tool), $allowed);

        if ($unknown !== []) {
            $key = reset($unknown);
            throw PayloadBuildException::invalidRequest(
                "Unknown option '$key' on server tool '$type'"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $tool
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function pickKeys(array $tool, array $keys): array
    {
        return array_intersect_key($tool, array_flip($keys));
    }

    /**
     * @param  list<string>  $serverToolTypes
     *
     * @throws PayloadBuildException
     */
    private function enforceMemoryUniqueness(array $serverToolTypes): void
    {
        $memoryCount = 0;
        foreach ($serverToolTypes as $type) {
            if (ToolTypeCatalog::isMemoryTool($type)) {
                $memoryCount++;
            }
        }

        if ($memoryCount > 1) {
            throw PayloadBuildException::invalidRequest('memory tool must appear at most once');
        }
    }
}
