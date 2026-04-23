<?php

declare(strict_types=1);

namespace App\Components\Claude\Files;

use App\Models\FileRecord;
use Illuminate\Database\Eloquent\Collection;

class FilesRepository
{
    public function findByFileId(string $fileId): ?FileRecord
    {
        return FileRecord::where('file_id', $fileId)
            ->where('is_deleted', false)
            ->first();
    }

    public function findForClient(string $fileId, string $clientId): ?FileRecord
    {
        return FileRecord::where('file_id', $fileId)
            ->where('client_id', $clientId)
            ->where('is_deleted', false)
            ->first();
    }

    /** @return Collection<int, FileRecord> */
    public function listForClient(
        string $clientId,
        int $limit,
        ?string $cursorCreatedAt,
        ?int $cursorId,
        ?FilePurpose $purpose,
    ): Collection {
        $query = FileRecord::where('client_id', $clientId)
            ->where('is_deleted', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($purpose !== null) {
            $query->where('upload_purpose', $purpose->value);
        }

        if ($cursorCreatedAt !== null && $cursorId !== null) {
            $query->where(function ($q) use ($cursorCreatedAt, $cursorId): void {
                $q->where('created_at', '<', $cursorCreatedAt)
                    ->orWhere(function ($q2) use ($cursorCreatedAt, $cursorId): void {
                        $q2->where('created_at', '=', $cursorCreatedAt)
                            ->where('id', '<', $cursorId);
                    });
            });
        }

        return $query->limit($limit)->get();
    }
}
