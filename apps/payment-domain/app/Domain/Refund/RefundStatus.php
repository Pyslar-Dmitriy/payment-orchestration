<?php

namespace App\Domain\Refund;

enum RefundStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
