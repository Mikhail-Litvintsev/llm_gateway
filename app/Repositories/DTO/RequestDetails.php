<?php

declare(strict_types=1);

namespace App\Repositories\DTO;

use App\Models\ApiRequest;
use App\Models\RequestRaw;
use App\Models\RequestUsage;

final readonly class RequestDetails
{
    public function __construct(
        public ?ApiRequest $request,
        public ?RequestUsage $usage,
        public ?RequestRaw $raw,
    ) {}

    public function exists(): bool
    {
        return $this->request !== null;
    }
}
