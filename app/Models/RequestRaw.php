<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $request_id
 * @property string $request_payload
 * @property ?string $response_payload
 * @property Carbon $retention_until
 */
class RequestRaw extends Model
{
    protected $table = 'request_raw';

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'request_payload',
        'response_payload',
        'retention_until',
    ];

    protected function casts(): array
    {
        return [
            'retention_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiRequest, $this> */
    public function apiRequest(): BelongsTo
    {
        return $this->belongsTo(ApiRequest::class, 'request_id', 'request_id');
    }
}
