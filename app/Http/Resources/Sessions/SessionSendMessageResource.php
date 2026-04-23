<?php

declare(strict_types=1);

namespace App\Http\Resources\Sessions;

use App\Components\Sessions\DTO\SessionSendMessageResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SessionSendMessageResource extends JsonResource
{
    /** @param SessionSendMessageResult $resource */
    public function __construct(SessionSendMessageResult $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var SessionSendMessageResult $result */
        $result = $this->resource;

        return [
            'session_id' => $result->publicId,
            'message_count' => $result->messageCount,
            'total_cost_usd' => $result->totalCostUsd,
            'stop_reason' => $result->stopReason,
            'content' => $result->assistantContent,
            'usage' => $result->usage,
            'model' => $result->model,
            'warnings' => $result->warnings,
        ];
    }
}
