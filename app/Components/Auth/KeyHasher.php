<?php

namespace App\Components\Auth;

class KeyHasher
{
    public function hash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    public function extractPrefix(string $apiKey): string
    {
        return substr($apiKey, 0, 8);
    }
}
