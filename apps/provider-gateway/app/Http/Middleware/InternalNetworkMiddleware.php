<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to requests originating from the internal Docker network
 * or loopback addresses.
 *
 * Allowed ranges:
 *   - 127.0.0.1 / ::1              (loopback)
 *   - 10.0.0.0/8                   (Docker overlay / class A private)
 *   - 172.16.0.0/12                (Docker bridge default range)
 *   - 192.168.0.0/16               (class C private)
 */
final class InternalNetworkMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if ($ip === null || ! $this->isInternalIp($ip)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }

    private function isInternalIp(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            // IPv6 loopback
            return $ip === '::1';
        }

        return $long === ip2long('127.0.0.1')
            || $this->inRange($long, '10.0.0.0', '10.255.255.255')
            || $this->inRange($long, '172.16.0.0', '172.31.255.255')
            || $this->inRange($long, '192.168.0.0', '192.168.255.255');
    }

    private function inRange(int $ip, string $start, string $end): bool
    {
        return $ip >= ip2long($start) && $ip <= ip2long($end);
    }
}
