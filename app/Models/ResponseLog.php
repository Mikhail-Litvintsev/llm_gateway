<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseLog extends Model
{
    public $timestamps = false;

    protected $table = 'response_log';

    protected $fillable = [
        'request_log_id',
        'status',
        'finish_reason',
        'input_tokens',
        'output_tokens',
        'cache_creation_tokens',
        'cache_read_tokens',
        'reasoning_tokens',
        'has_tool_calls',
        'tool_calls_count',
        'error_code',
        'error_message',
        'provider_used',
        'model_used',
        'is_fallback',
        'latency_ms',
        'structured_output_fallback',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'has_tool_calls' => 'boolean',
            'is_fallback' => 'boolean',
            'structured_output_fallback' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function requestLog(): BelongsTo
    {
        return $this->belongsTo(RequestLog::class);
    }
}
