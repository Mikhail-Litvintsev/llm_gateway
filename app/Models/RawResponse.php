<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawResponse extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_log_id',
        'provider',
        'model',
        'http_status',
        'response_body',
        'response_headers',
        'is_fallback_attempt',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_headers' => 'array',
            'is_fallback_attempt' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function requestLog(): BelongsTo
    {
        return $this->belongsTo(RequestLog::class);
    }
}
