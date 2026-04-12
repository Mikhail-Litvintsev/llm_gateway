<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowed_features' => 'array',
            'is_dev_mode' => 'boolean',
            'monthly_spend_cap_usd' => 'decimal:2',
            'current_month_spend_usd' => 'decimal:4',
            'signing_secret_rotated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'api_key_hash' => 'string',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ClaudeWorkspace::class, 'workspace_id');
    }
}
