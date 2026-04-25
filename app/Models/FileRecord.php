<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property string $file_id
 * @property string $anthropic_file_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $upload_purpose
 * @property bool $is_deleted
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class FileRecord extends Model
{
    protected $table = 'files';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
