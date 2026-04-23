<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $session_id
 * @property int $turn_index
 * @property string $role
 * @property array $content
 * @property ?string $stop_reason
 * @property ?array $usage
 * @property ?string $model
 * @property ?string $request_id
 * @property ?Carbon $created_at
 */
class SessionMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'turn_index',
        'role',
        'content',
        'stop_reason',
        'usage',
        'model',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'usage' => 'array',
            'turn_index' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
