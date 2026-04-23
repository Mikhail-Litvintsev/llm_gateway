<?php

declare(strict_types=1);

namespace App\Components\Sessions\Memory;

use App\Components\Sessions\Exceptions\MemoryPathException;

final class MemoryPathValidator
{
    public static function validate(string $raw, bool $allowRoot): string
    {
        if (str_contains($raw, "\x00")) {
            throw new MemoryPathException('Null byte not allowed in path');
        }

        if (preg_match('/[\x01-\x1F\x7F]/', $raw)) {
            throw new MemoryPathException('Control character not allowed in path');
        }

        if (str_contains($raw, '\\')) {
            throw new MemoryPathException('Backslash not allowed in path');
        }

        if (str_contains($raw, '%')) {
            throw new MemoryPathException('Percent-encoding not allowed in path');
        }

        if (! str_starts_with($raw, '/memories')) {
            throw new MemoryPathException('Path must start with /memories');
        }

        if (strlen($raw) > 1024) {
            throw new MemoryPathException('Path too long');
        }

        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $raw)) {
            throw new MemoryPathException('Path traversal not allowed');
        }

        $path = rtrim($raw, '/');

        if ($path === '/memories') {
            if (! $allowRoot) {
                throw new MemoryPathException('Path must point to a file or subdirectory');
            }

            return '/memories';
        }

        if (! preg_match('~\A/memories(/[A-Za-z0-9._-]+)+\z~', $path)) {
            $afterMemories = substr($path, strlen('/memories'));

            if (str_contains($afterMemories, '//')) {
                throw new MemoryPathException('Invalid path segment');
            }

            $segments = explode('/', ltrim($afterMemories, '/'));
            foreach ($segments as $segment) {
                if ($segment === '' || str_starts_with($segment, '.') || strlen($segment) > 128) {
                    throw new MemoryPathException('Invalid path segment');
                }
            }

            throw new MemoryPathException('Invalid character in path');
        }

        $segments = explode('/', substr($path, strlen('/memories/')));
        foreach ($segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '.') || strlen($segment) > 128) {
                throw new MemoryPathException('Invalid path segment');
            }
        }

        return $path;
    }
}
