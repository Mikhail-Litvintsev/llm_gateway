<?php

namespace App\Models;

use App\Components\RequestPipeline\Enums\Priority;
use App\Components\RequestPipeline\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RequestLog extends Model
{
    use HasFactory;

    protected $table = 'request_log';

    protected $fillable = [
        'request_id',
        'api_client_id',
        'session_id',
        'step_id',
        'provider_requested',
        'model_requested',
        'provider_used',
        'model_used',
        'is_fallback',
        'priority',
        'status',
        'callback_url',
        'meta_data',
        'has_tools',
        'has_media',
        'stream',
        'idempotency_key',
        'ip_address',
        'error_code',
        'error_message',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'meta_data' => 'array',
            'has_tools' => 'boolean',
            'has_media' => 'boolean',
            'stream' => 'boolean',
            'is_fallback' => 'boolean',
            'priority' => Priority::class,
            'status' => RequestStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('api_client_id', $clientId);
    }

    public function scopeInPeriod(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        return $query;
    }

    public function scopeByStatus(Builder $query, RequestStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function responseLog(): HasOne
    {
        return $this->hasOne(ResponseLog::class);
    }

    public function rawResponses(): HasMany
    {
        return $this->hasMany(RawResponse::class);
    }

    public function pendingPrompt(): HasOne
    {
        return $this->hasOne(PendingPrompt::class);
    }

    public function pendingResponse(): HasOne
    {
        return $this->hasOne(PendingResponse::class);
    }

    public function sessionHistory(): HasOne
    {
        return $this->hasOne(SessionHistory::class);
    }
}
