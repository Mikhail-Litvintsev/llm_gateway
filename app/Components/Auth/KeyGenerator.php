<?php

declare(strict_types=1);

namespace App\Components\Auth;

final class KeyGenerator
{
    private const string BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function generateRawKey(): string
    {
        return 'gw_live_'.$this->randomBase62(32);
    }

    public function derivePrefix(string $rawKey): string
    {
        return substr($rawKey, 0, 12);
    }

    /**
     * @param  positive-int  $length
     */
    private function randomBase62(int $length): string
    {
        $bytes = random_bytes($length);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= self::BASE62[ord($bytes[$i]) % 62];
        }

        return $result;
    }
}
