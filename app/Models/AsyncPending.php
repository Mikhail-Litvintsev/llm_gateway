<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $request_id
 * @property string $payload_for_anthropic
 * @property string $callback_url
 * @property string $status
 * @property int $callback_attempts
 * @property ?Carbon $next_attempt_at
 * @property Carbon $expires_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AsyncPending extends Model
{
    protected $table = 'async_pending';

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'request_id',
        'payload_for_anthropic',
        'callback_url',
        'status',
        'callback_attempts',
        'next_attempt_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'callback_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiRequest, $this> */
    public function apiRequest(): BelongsTo
    {
        return $this->belongsTo(ApiRequest::class, 'request_id', 'request_id');
    }
}
