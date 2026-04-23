<?php

declare(strict_types=1);

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocsLinksTest extends TestCase
{
    /** @var array<string, string> */
    private array $docFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->docFiles = $this->collectDocFiles();
    }

    #[Test]
    public function all_relative_links_resolve_to_existing_files(): void
    {
        $broken = [];

        foreach ($this->docFiles as $label => $filePath) {
            $content = file_get_contents($filePath);
            $dir = dirname($filePath);

            preg_match_all('/\[.+?\]\(([^)]+)\)/', $content, $matches);

            foreach ($matches[1] as $link) {
                if (str_starts_with($link, 'http') || str_starts_with($link, '#') || str_starts_with($link, 'mailto:')) {
                    continue;
                }

                $linkPath = explode('#', $link)[0];
                if ($linkPath === '') {
                    continue;
                }

                $resolved = realpath($dir.'/'.$linkPath);
                if ($resolved === false || ! file_exists($resolved)) {
                    $broken[] = "{$label}: broken link [{$link}]";
                }
            }
        }

        $this->assertEmpty($broken, "Broken links found:\n".implode("\n", $broken));
    }

    /** @return array<string, string> */
    private function collectDocFiles(): array
    {
        $files = [];

        $claudeMd = base_path('CLAUDE.md');
        if (file_exists($claudeMd)) {
            $files['CLAUDE.md'] = $claudeMd;
        }

        foreach (glob(base_path('documentation/*.md')) as $file) {
            $files['documentation/'.basename($file)] = $file;
        }

        return $files;
    }
}
