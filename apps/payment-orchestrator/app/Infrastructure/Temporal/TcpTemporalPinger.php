<?php

namespace App\Infrastructure\Temporal;

class TcpTemporalPinger implements TemporalPinger
{
    public function __construct(private readonly string $address) {}

    public function isReachable(): bool
    {
        [$host, $port] = array_pad(explode(':', $this->address, 2), 2, '7233');

        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 2.0);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
