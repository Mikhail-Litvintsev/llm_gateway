<?php

declare(strict_types=1);

namespace App\Models;

use App\Components\Claude\Enums\BatchItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $batch_id
 * @property string $custom_id
 * @property string $payload
 * @property BatchItemStatus $status
 * @property ?string $result_payload
 * @property ?string $error_type
 * @property ?string $error_message
 * @property ?string $request_id
 */
class BatchItem extends Model
{
    protected $table = 'batch_items';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BatchItemStatus::class,
        ];
    }

    /** @return BelongsTo<BatchRecord, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BatchRecord::class, 'batch_id', 'id');
    }
}
