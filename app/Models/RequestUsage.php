<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $request_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_creation_5m_tokens
 * @property int $cache_creation_1h_tokens
 * @property int $cache_read_tokens
 * @property int $thinking_tokens
 * @property int $server_tool_web_search_count
 * @property int $server_tool_web_fetch_count
 * @property int $server_tool_code_exec_count
 * @property int $server_tool_tool_search_count
 * @property string $cost_usd
 * @property ?array<string, mixed> $cost_breakdown
 * @property ?array<int, array<string, mixed>> $iterations_json
 * @property ?array<string, string> $rate_limit_headers
 */
class RequestUsage extends Model
{
    protected $table = 'request_usage';

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'input_tokens',
        'output_tokens',
        'cache_creation_5m_tokens',
        'cache_creation_1h_tokens',
        'cache_read_tokens',
        'thinking_tokens',
        'server_tool_web_search_count',
        'server_tool_web_fetch_count',
        'server_tool_code_exec_count',
        'server_tool_tool_search_count',
        'cost_usd',
        'cost_breakdown',
        'iterations_json',
        'rate_limit_headers',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_creation_5m_tokens' => 'integer',
            'cache_creation_1h_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'thinking_tokens' => 'integer',
            'server_tool_web_search_count' => 'integer',
            'server_tool_web_fetch_count' => 'integer',
            'server_tool_code_exec_count' => 'integer',
            'server_tool_tool_search_count' => 'integer',
            'cost_breakdown' => 'array',
            'iterations_json' => 'array',
            'rate_limit_headers' => 'array',
        ];
    }

    /** @return BelongsTo<ApiRequest, $this> */
    public function apiRequest(): BelongsTo
    {
        return $this->belongsTo(ApiRequest::class, 'request_id', 'request_id');
    }
}
