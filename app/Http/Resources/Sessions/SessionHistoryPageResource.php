<?php

declare(strict_types=1);

namespace App\Http\Resources\Sessions;

use App\Components\Sessions\DTO\SessionHistoryPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SessionHistoryPageResource extends JsonResource
{
    public function __construct(SessionHistoryPage $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var SessionHistoryPage $page */
        $page = $this->resource;

        return [
            'from' => $page->from,
            'limit' => $page->limit,
            'total' => $page->total,
            'messages' => $page->messages,
        ];
    }
}
