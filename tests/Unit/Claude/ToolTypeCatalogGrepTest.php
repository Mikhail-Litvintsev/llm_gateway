<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\ToolTypeCatalog;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class ToolTypeCatalogGrepTest extends TestCase
{
    #[Test]
    public function no_magic_strings_outside_catalog(): void
    {
        $magicStrings = [
            ToolTypeCatalog::WEB_SEARCH,
            ToolTypeCatalog::WEB_FETCH,
            ToolTypeCatalog::CODE_EXECUTION,
            ToolTypeCatalog::TOOL_SEARCH_REGEX,
            ToolTypeCatalog::TOOL_SEARCH_BM25,
            ToolTypeCatalog::MEMORY,
            ToolTypeCatalog::BASH,
            ToolTypeCatalog::TEXT_EDITOR,
            ToolTypeCatalog::COMPUTER,
            ToolTypeCatalog::EDIT_COMPACT,
            ToolTypeCatalog::EDIT_CLEAR_TOOL_USES,
            ToolTypeCatalog::EDIT_CLEAR_THINKING,
            ToolTypeCatalog::BETA_COMPACT,
            ToolTypeCatalog::BETA_CONTEXT_MANAGEMENT,
            ToolTypeCatalog::BETA_COMPUTER_USE,
        ];

        $appDir = base_path('app');
        $catalogFile = realpath(base_path('app/Components/Claude/ToolTypeCatalog.php'));
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            if ($filePath === $catalogFile) {
                continue;
            }

            $contents = file_get_contents($filePath);

            foreach ($magicStrings as $magic) {
                if (str_contains($contents, "'$magic'") || str_contains($contents, "\"$magic\"")) {
                    $violations[] = "$filePath contains hardcoded '$magic'";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Magic strings found outside ToolTypeCatalog:\n".implode("\n", $violations),
        );
    }
}
