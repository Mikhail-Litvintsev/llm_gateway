<?php

namespace App\Components\Auth;

use App\Models\CallbackUrl;

class CallbackUrlValidator
{
    public function validate(int $clientId, string $url): bool
    {
        $parsed = parse_url($url);
        $normalizedUrl = ($parsed['scheme'] ?? '') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '/');

        return CallbackUrl::where('api_client_id', $clientId)
            ->where('is_active', true)
            ->get()
            ->contains(function (CallbackUrl $callbackUrl) use ($normalizedUrl) {
                $parsed = parse_url($callbackUrl->url);
                $stored = ($parsed['scheme'] ?? '') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '/');
                return $stored === $normalizedUrl;
            });
    }

    public function isSecure(string $url): bool
    {
        // В локальном/тестовом окружении разрешаем HTTP для межконтейнерного взаимодействия
        if (app()->environment('local', 'testing')) {
            return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
        }

        return str_starts_with($url, 'https://');
    }
}
