<?php

declare(strict_types=1);

namespace App\Models;

use App\Components\Claude\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $batch_id
 * @property int $client_id
 * @property ?string $anthropic_batch_id
 * @property BatchStatus $status
 * @property int $request_count
 * @property int $succeeded_count
 * @property int $errored_count
 * @property int $cancelled_count
 * @property int $expired_count
 * @property ?string $callback_url
 * @property bool $auto_use_1h_cache
 * @property int $submit_attempts
 * @property string $total_cost_usd
 * @property ?string $cache_hit_ratio
 * @property ?string $total_savings_from_caching_usd
 * @property int $total_cache_read_tokens
 * @property int $total_input_tokens
 * @property int $total_output_tokens
 * @property ?\Illuminate\Support\Carbon $submitted_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?\Illuminate\Support\Carbon $last_polled_at
 * @property int $poll_attempts
 * @property ?string $results_url
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class BatchRecord extends Model
{
    protected $table = 'batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'auto_use_1h_cache' => 'boolean',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BatchItem::class, 'batch_id', 'id');
    }
}
