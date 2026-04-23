<?php

declare(strict_types=1);

namespace Tests\Unit\Sessions;

use App\Components\Sessions\Exceptions\MemoryPathException;
use App\Components\Sessions\Memory\MemoryPathValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MemoryPathValidatorTest extends TestCase
{
    private MemoryPathValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new MemoryPathValidator;
    }

    #[Test]
    public function rejects_path_not_starting_with_memories(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Path must start with /memories');

        $this->validator->validate('/etc/passwd', allowRoot: false);
    }

    #[Test]
    public function rejects_dotdot_segment(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Path traversal not allowed');

        $this->validator->validate('/memories/../etc/passwd', allowRoot: false);
    }

    #[Test]
    public function rejects_backslash(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Backslash not allowed in path');

        $this->validator->validate('/memories\notes', allowRoot: false);
    }

    #[Test]
    public function rejects_null_byte(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Null byte not allowed in path');

        $this->validator->validate("/memories/notes\x00.md", allowRoot: false);
    }

    #[Test]
    public function rejects_control_character(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Control character not allowed in path');

        $this->validator->validate("/memories/notes\x01.md", allowRoot: false);
    }

    #[Test]
    public function rejects_percent_encoding(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Percent-encoding not allowed in path');

        $this->validator->validate('/memories/notes%2e%2e', allowRoot: false);
    }

    #[Test]
    public function rejects_empty_segment(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Invalid path segment');

        $this->validator->validate('/memories//notes', allowRoot: false);
    }

    #[Test]
    public function rejects_segment_over_128_chars(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Invalid path segment');

        $segment = str_repeat('a', 129);
        $this->validator->validate('/memories/'.$segment, allowRoot: false);
    }

    #[Test]
    public function rejects_total_path_over_1024_chars(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Path too long');

        $segment = str_repeat('a', 120);
        $path = '/memories';
        while (strlen($path) <= 1024) {
            $path .= '/'.$segment;
        }

        $this->validator->validate($path, allowRoot: false);
    }

    #[Test]
    public function rejects_character_outside_allowlist(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Invalid character in path');

        $this->validator->validate('/memories/notes/todo@file.md', allowRoot: false);
    }

    #[Test]
    public function rejects_root_when_allow_root_false(): void
    {
        $this->expectException(MemoryPathException::class);
        $this->expectExceptionMessage('Path must point to a file or subdirectory');

        $this->validator->validate('/memories', allowRoot: false);
    }

    #[Test]
    public function accepts_valid_nested_path(): void
    {
        $result = $this->validator->validate('/memories/notes/todo.md', allowRoot: false);

        $this->assertSame('/memories/notes/todo.md', $result);
    }

    #[Test]
    public function accepts_dot_in_filename(): void
    {
        $result = $this->validator->validate('/memories/file.txt', allowRoot: false);

        $this->assertSame('/memories/file.txt', $result);
    }

    #[Test]
    public function accepts_underscore_and_hyphen(): void
    {
        $result = $this->validator->validate('/memories/my-notes/file_2.md', allowRoot: false);

        $this->assertSame('/memories/my-notes/file_2.md', $result);
    }
}
