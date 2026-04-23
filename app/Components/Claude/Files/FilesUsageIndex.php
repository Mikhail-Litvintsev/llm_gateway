<?php

declare(strict_types=1);

namespace App\Components\Claude\Files;

use App\Models\FileRecord;
use Illuminate\Support\Facades\DB;

final class FilesUsageIndex
{
    public function isReferenced(FileRecord $fileRecord): bool
    {
        $cutoff = now()->subDays((int) config('llm.claude.files.unused_alert_days'));

        return DB::table('request_raw')
            ->where('created_at', '>=', $cutoff)
            ->where(function ($query) use ($fileRecord): void {
                $query->where('payload', 'like', '%'.$fileRecord->file_id.'%')
                    ->orWhere('payload', 'like', '%'.$fileRecord->anthropic_file_id.'%');
            })
            ->limit(1)
            ->exists();
    }
}
