<?php

namespace App\Infrastructure\Temporal;

interface TemporalPinger
{
    public function isReachable(): bool;
}
