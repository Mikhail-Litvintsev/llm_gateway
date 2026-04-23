<?php

namespace App\Components\Auth;

class KeyGenerator
{
    public function generate(string $prefix = 'lgw_'): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
