<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionHistory extends Model
{
    public $timestamps = false;

    protected $table = 'session_history';

    protected $fillable = [
        'session_id',
        'api_client_id',
        'step_id',
        'request_log_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function scopeForSession(Builder $query, string $sessionId, int $clientId): Builder
    {
        return $query->where('session_id', $sessionId)
            ->where('api_client_id', $clientId)
            ->orderBy('step_id');
    }

    public function requestLog(): BelongsTo
    {
        return $this->belongsTo(RequestLog::class);
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }
}
