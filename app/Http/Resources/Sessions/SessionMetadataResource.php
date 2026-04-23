<?php

declare(strict_types=1);

namespace App\Http\Resources\Sessions;

use App\Components\Sessions\DTO\SessionMetadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SessionMetadataResource extends JsonResource
{
    public function __construct(SessionMetadata $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var SessionMetadata $meta */
        $meta = $this->resource;

        return [
            'session_id' => $meta->publicId,
            'model_alias' => $meta->modelAlias,
            'message_count' => $meta->messageCount,
            'last_compaction_at' => $meta->lastCompactionAt?->format('c'),
            'compaction_count' => $meta->compactionCount,
            'expires_at' => $meta->expiresAt?->format('c'),
            'status' => $meta->status,
            'total_cost_usd' => $meta->totalCostUsd,
        ];
    }
}
