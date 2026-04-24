<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $request_id
 * @property int $client_id
 * @property string $endpoint
 * @property string $mode
 * @property string $model_alias
 * @property string $model_snapshot
 * @property ?string $anthropic_request_id
 * @property ?string $anthropic_organization_id
 * @property string $status
 * @property ?int $http_status
 * @property ?string $error_type
 * @property ?string $error_message
 * @property ?string $service_tier_used
 * @property Carbon $created_at
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 */
class ApiRequest extends Model
{
    protected $table = 'requests';

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'client_id',
        'endpoint',
        'mode',
        'model_alias',
        'model_snapshot',
        'anthropic_request_id',
        'anthropic_organization_id',
        'status',
        'http_status',
        'error_type',
        'error_message',
        'service_tier_used',
        'created_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return HasOne<AsyncPending, $this> */
    public function asyncPending(): HasOne
    {
        return $this->hasOne(AsyncPending::class, 'request_id', 'request_id');
    }

    /** @return HasOne<RequestUsage, $this> */
    public function usage(): HasOne
    {
        return $this->hasOne(RequestUsage::class, 'request_id', 'request_id');
    }

    /** @return HasOne<RequestRaw, $this> */
    public function raw(): HasOne
    {
        return $this->hasOne(RequestRaw::class, 'request_id', 'request_id');
    }
}
