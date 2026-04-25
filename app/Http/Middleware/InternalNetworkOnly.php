<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalNetworkOnly
{
    private const ALLOWED_NETWORKS = [
        ['network' => '172.16.0.0', 'mask' => 12],
        ['network' => '10.0.0.0', 'mask' => 8],
        ['network' => '192.168.0.0', 'mask' => 16],
    ];

    private const ALLOWED_IPS = [
        '127.0.0.1',
        '::1',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if ($ip === null || ! $this->isAllowed($ip)) {
            abort(403, 'Access denied');
        }

        return $next($request);
    }

    private function isAllowed(string $ip): bool
    {
        if (in_array($ip, self::ALLOWED_IPS, true)) {
            return true;
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach (self::ALLOWED_NETWORKS as $network) {
            $networkLong = ip2long($network['network']);
            $mask = ~((1 << (32 - $network['mask'])) - 1);

            if (($ipLong & $mask) === ($networkLong & $mask)) {
                return true;
            }
        }

        return false;
    }
}
