<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $session_id
 * @property string $path
 * @property string $content
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SessionMemoryFile extends Model
{
    protected $fillable = [
        'session_id',
        'path',
        'content',
    ];

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
