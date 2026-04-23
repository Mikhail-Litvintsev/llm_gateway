<?php

declare(strict_types=1);

namespace App\Components\Sessions;

use App\Components\Sessions\DTO\MemoryCommandResult;
use App\Components\Sessions\Enums\MemoryCommand;
use App\Components\Sessions\Exceptions\MemoryFileExistsException;
use App\Components\Sessions\Exceptions\MemoryFileNotFoundException;
use App\Components\Sessions\Exceptions\MemoryPathException;
use App\Components\Sessions\Memory\MemoryPathValidator;
use App\Models\Session;
use App\Models\SessionMemoryFile;
use Illuminate\Database\DatabaseManager;

final readonly class MemoryHandler
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function execute(Session $session, array $toolUse): MemoryCommandResult
    {
        $toolUseId = $toolUse['id'] ?? '';
        $input = $toolUse['input'] ?? [];
        $rawCommand = $input['command'] ?? null;

        $command = is_string($rawCommand) ? MemoryCommand::tryFrom($rawCommand) : null;

        if ($command === null) {
            return new MemoryCommandResult($toolUseId, "Unknown command: $rawCommand", true);
        }

        try {
            $text = match ($command) {
                MemoryCommand::View => $this->view($session, $input),
                MemoryCommand::Create => $this->create($session, $input),
                MemoryCommand::StrReplace => $this->strReplace($session, $input),
                MemoryCommand::Insert => $this->insert($session, $input),
                MemoryCommand::Delete => $this->delete($session, $input),
                MemoryCommand::Rename => $this->rename($session, $input),
            };

            return new MemoryCommandResult($toolUseId, $text, false);
        } catch (MemoryPathException|MemoryFileNotFoundException|MemoryFileExistsException $e) {
            return new MemoryCommandResult($toolUseId, $e->getMessage(), true);
        }
    }

    private function view(Session $session, array $input): string
    {
        $path = MemoryPathValidator::validate($input['path'] ?? '', allowRoot: true);
        $viewRange = $input['view_range'] ?? null;

        $file = $this->findFile($session, $path);

        if ($file !== null) {
            return $this->viewFile($file, $viewRange);
        }

        return $this->viewDirectory($session, $path);
    }

    private function viewFile(SessionMemoryFile $file, ?array $viewRange): string
    {
        $lines = explode("\n", $file->content);

        if ($viewRange !== null) {
            $start = max(1, (int) ($viewRange[0] ?? 1));
            $end = min(count($lines), (int) ($viewRange[1] ?? count($lines)));
            $lines = array_slice($lines, $start - 1, $end - $start + 1);
            $lineOffset = $start;
        } else {
            $lineOffset = 1;
        }

        $numbered = [];
        foreach ($lines as $i => $line) {
            $numbered[] = ($lineOffset + $i).": $line";
        }

        return implode("\n", $numbered);
    }

    private function viewDirectory(Session $session, string $path): string
    {
        $query = SessionMemoryFile::where('session_id', $session->id);

        if ($path !== '/memories') {
            $query->where(function ($q) use ($path) {
                $q->where('path', $path)
                    ->orWhere('path', 'LIKE', "$path/%");
            });
        }

        $paths = $query->orderBy('path')->pluck('path')->all();

        if ($paths === []) {
            return "Directory $path is empty.";
        }

        return implode("\n", $paths);
    }

    private function create(Session $session, array $input): string
    {
        $path = MemoryPathValidator::validate($input['path'] ?? '', allowRoot: false);
        $fileText = $input['file_text'] ?? '';

        $this->db->transaction(function () use ($session, $path, $fileText): void {
            SessionMemoryFile::updateOrCreate(
                ['session_id' => $session->id, 'path' => $path],
                ['content' => $fileText],
            );
        });

        return "File created successfully at $path";
    }

    private function strReplace(Session $session, array $input): string
    {
        $path = MemoryPathValidator::validate($input['path'] ?? '', allowRoot: false);
        $oldStr = $input['old_str'] ?? '';
        $newStr = $input['new_str'] ?? '';

        return $this->db->transaction(function () use ($session, $path, $oldStr, $newStr): string {
            $file = $this->findFileOrFail($session, $path);
            $content = $file->content;

            if (substr_count($content, $oldStr) !== 1) {
                throw new MemoryPathException("old_str must appear exactly once in $path");
            }

            $file->content = str_replace($oldStr, $newStr, $content);
            $file->save();

            return "File $path has been edited.";
        });
    }

    private function insert(Session $session, array $input): string
    {
        $path = MemoryPathValidator::validate($input['path'] ?? '', allowRoot: false);
        $insertLine = (int) ($input['insert_line'] ?? -1);
        $insertText = $input['insert_text'] ?? '';

        return $this->db->transaction(function () use ($session, $path, $insertLine, $insertText): string {
            $file = $this->findFileOrFail($session, $path);
            $lines = explode("\n", $file->content);

            if ($insertLine < 0 || $insertLine > count($lines)) {
                throw new MemoryPathException("insert_line $insertLine out of range for $path (0..".count($lines).')');
            }

            array_splice($lines, $insertLine, 0, [$insertText]);
            $file->content = implode("\n", $lines);
            $file->save();

            return "Text inserted into $path.";
        });
    }

    private function delete(Session $session, array $input): string
    {
        $path = MemoryPathValidator::validate($input['path'] ?? '', allowRoot: false);

        return $this->db->transaction(function () use ($session, $path): string {
            $deleted = SessionMemoryFile::where('session_id', $session->id)
                ->where(function ($q) use ($path) {
                    $q->where('path', $path)
                        ->orWhere('path', 'LIKE', "$path/%");
                })
                ->delete();

            if ($deleted === 0) {
                throw new MemoryFileNotFoundException("Not found: $path");
            }

            $isDir = $deleted > 1 || ! str_contains(basename($path), '.');

            return $isDir ? "Directory deleted: $path" : "File deleted: $path";
        });
    }

    private function rename(Session $session, array $input): string
    {
        $oldPath = MemoryPathValidator::validate($input['old_path'] ?? '', allowRoot: false);
        $newPath = MemoryPathValidator::validate($input['new_path'] ?? '', allowRoot: false);

        return $this->db->transaction(function () use ($session, $oldPath, $newPath): string {
            $this->findFileOrFail($session, $oldPath);

            $existing = $this->findFile($session, $newPath);
            if ($existing !== null) {
                throw new MemoryFileExistsException("File already exists: $newPath");
            }

            SessionMemoryFile::where('session_id', $session->id)
                ->where('path', $oldPath)
                ->update(['path' => $newPath]);

            return "File renamed: $oldPath → $newPath";
        });
    }

    private function findFile(Session $session, string $path): ?SessionMemoryFile
    {
        return SessionMemoryFile::where('session_id', $session->id)
            ->where('path', $path)
            ->first();
    }

    private function findFileOrFail(Session $session, string $path): SessionMemoryFile
    {
        return $this->findFile($session, $path)
            ?? throw new MemoryFileNotFoundException("Not found: $path");
    }
}
