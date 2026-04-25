<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $api_key_hash
 * @property ?string $signing_secret
 * @property array<string, mixed> $allowed_features
 * @property bool $is_dev_mode
 * @property ?string $monthly_spend_cap_usd
 * @property ?string $current_month_spend_usd
 * @property ?int $rate_limit_rpm
 * @property ?int $workspace_id
 * @property ?string $default_model_alias
 * @property ?string $inference_geo
 * @property ?string $anthropic_workspace_id
 * @property ?string $signing_secret_current_encrypted
 * @property ?string $signing_secret_previous_encrypted
 * @property ?Carbon $signing_secret_rotated_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
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

    /** @return BelongsTo<ClaudeWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ClaudeWorkspace::class, 'workspace_id');
    }

    /** @return HasMany<ClientCallbackUrl, $this> */
    public function callbackUrls(): HasMany
    {
        return $this->hasMany(ClientCallbackUrl::class);
    }
}
