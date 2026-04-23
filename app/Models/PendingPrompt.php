<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingPrompt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_log_id',
        'prompt_xml',
        'tools_xml',
        'parameters_xml',
        'provider_xml',
        'assembled_payload',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'assembled_payload' => 'array',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }

    public function requestLog(): BelongsTo
    {
        return $this->belongsTo(RequestLog::class);
    }
}
