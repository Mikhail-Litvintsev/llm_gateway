<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ClientCallbackUrl;

final class CallbackUrlRepository
{
    public function isWhitelisted(int $clientId, string $url): bool
    {
        return ClientCallbackUrl::query()
            ->where('client_id', $clientId)
            ->where('url', $url)
            ->where('is_active', true)
            ->exists();
    }
}
