<?php

declare(strict_types=1);

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocsExamplesValidationTest extends TestCase
{
    #[Test]
    public function all_json_request_examples_are_valid_json(): void
    {
        $guide = file_get_contents(base_path('documentation/client_integration_guide.md'));

        preg_match_all('/```(?:json|http)\s*\n(.*?)```/s', $guide, $matches);

        $invalid = [];
        foreach ($matches[1] as $index => $block) {
            $trimmed = trim($block);
            if ($trimmed === '' || ! str_starts_with($trimmed, '{')) {
                continue;
            }

            if ($this->containsPlaceholders($trimmed)) {
                continue;
            }

            if ($this->isJsonLines($trimmed)) {
                $this->validateJsonLines($trimmed, $index, $invalid);

                continue;
            }

            if (! json_validate($trimmed)) {
                $preview = substr($trimmed, 0, 80);
                $invalid[] = "Block #$index: invalid JSON — $preview...";
            }
        }

        $this->assertEmpty($invalid, "Invalid JSON blocks in client_integration_guide.md:\n".implode("\n", $invalid));
    }

    #[Test]
    public function request_examples_contain_required_fields(): void
    {
        $guide = file_get_contents(base_path('documentation/client_integration_guide.md'));

        preg_match_all('/```json\s*\n(.*?)```/s', $guide, $matches);

        $malformed = [];
        foreach ($matches[1] as $index => $block) {
            $trimmed = trim($block);
            if ($this->containsPlaceholders($trimmed)) {
                continue;
            }

            $decoded = json_decode($trimmed, true);

            if ($decoded === null) {
                continue;
            }

            if (! isset($decoded['model']) || ! isset($decoded['messages'])) {
                continue;
            }

            if (! is_string($decoded['model'])) {
                $malformed[] = "Block #$index: 'model' is not a string";
            }
            if (! is_array($decoded['messages'])) {
                $malformed[] = "Block #$index: 'messages' is not an array";
            }
        }

        $this->assertEmpty($malformed, "Malformed request examples:\n".implode("\n", $malformed));
    }

    #[Test]
    public function response_examples_are_valid_json(): void
    {
        $guide = file_get_contents(base_path('documentation/client_integration_guide.md'));

        preg_match_all('/```json\s*\n(.*?)```/s', $guide, $matches);

        $invalid = [];
        foreach ($matches[1] as $index => $block) {
            $trimmed = trim($block);
            if ($trimmed === '' || ! str_starts_with($trimmed, '{')) {
                continue;
            }

            if ($this->containsPlaceholders($trimmed)) {
                continue;
            }

            if ($this->isJsonLines($trimmed)) {
                $this->validateJsonLines($trimmed, $index, $invalid);

                continue;
            }

            if (! json_validate($trimmed)) {
                $preview = substr($trimmed, 0, 80);
                $invalid[] = "Block #$index: invalid JSON — $preview...";
            }
        }

        $this->assertEmpty($invalid, "Invalid JSON response blocks:\n".implode("\n", $invalid));
    }

    private function containsPlaceholders(string $json): bool
    {
        return (bool) preg_match('/\[\.{3}]|\.{3}"|"\.\.\."/', $json);
    }

    private function isJsonLines(string $text): bool
    {
        $lines = array_filter(explode("\n", trim($text)), fn (string $l): bool => trim($l) !== '');

        if (count($lines) < 2) {
            return false;
        }

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '' && str_starts_with($t, '{') && str_ends_with($t, '}')) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param list<string> $invalid */
    private function validateJsonLines(string $text, int $blockIndex, array &$invalid): void
    {
        foreach (explode("\n", trim($text)) as $lineNum => $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }

            if ($this->containsPlaceholders($t)) {
                continue;
            }

            if (! json_validate($t)) {
                $invalid[] = "Block #$blockIndex line $lineNum: invalid JSONL";
            }
        }
    }
}
