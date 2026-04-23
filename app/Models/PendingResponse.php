<?php

namespace App\Models;

use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingResponse extends Model
{
    protected $fillable = [
        'request_log_id',
        'response_payload',
        'callback_url',
        'callback_method',
        'callback_headers',
        'delivery_status',
        'delivery_attempts',
        'max_attempts',
        'retry_backoff',
        'retry_initial_delay',
        'last_attempt_at',
        'last_error',
        'next_retry_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'callback_headers' => 'array',
            'delivery_status' => DeliveryStatus::class,
            'expires_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }

    public function scopeReadyForRetry(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('delivery_status', DeliveryStatus::Pending)
              ->orWhere(function (Builder $q2) {
                  $q2->where('delivery_status', DeliveryStatus::Delivering)
                     ->where('next_retry_at', '<=', now());
              });
        });
    }

    public function requestLog(): BelongsTo
    {
        return $this->belongsTo(RequestLog::class);
    }
}
