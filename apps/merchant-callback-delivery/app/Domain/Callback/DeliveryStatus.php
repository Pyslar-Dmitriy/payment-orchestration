<?php

declare(strict_types=1);

namespace App\Domain\Callback;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Dlq = 'dlq';
}
