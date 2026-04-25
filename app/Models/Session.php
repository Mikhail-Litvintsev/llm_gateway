<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $session_id
 * @property int $client_id
 * @property ?int $workspace_id
 * @property ?string $model_alias
 * @property ?string $system
 * @property ?array<int, array<string, mixed>> $tools
 * @property ?array<int, array<string, mixed>> $mcp_servers
 * @property string $cache_strategy
 * @property ?array<string, mixed> $context_management
 * @property int $total_input_tokens
 * @property int $total_output_tokens
 * @property string $total_cost_usd
 * @property int $message_count
 * @property int $compaction_count
 * @property bool $auto_resume
 * @property ?Carbon $last_compaction_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class Session extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'workspace_id',
        'model_alias',
        'system',
        'tools',
        'mcp_servers',
        'cache_strategy',
        'context_management',
        'auto_resume',
        'message_count',
        'last_compaction_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'mcp_servers' => 'array',
            'context_management' => 'array',
            'last_compaction_at' => 'datetime',
            'expires_at' => 'datetime',
            'auto_resume' => 'boolean',
            'message_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'session_id';
    }

    /** @return HasMany<SessionMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SessionMessage::class);
    }

    /** @return HasMany<SessionMemoryFile, $this> */
    public function memoryFiles(): HasMany
    {
        return $this->hasMany(SessionMemoryFile::class);
    }
}
