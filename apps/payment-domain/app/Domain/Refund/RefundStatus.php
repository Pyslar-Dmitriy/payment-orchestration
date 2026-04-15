<?php

namespace App\Domain\Refund;

enum RefundStatus: string
{
    case PENDING = 'pending';
    case PENDING_PROVIDER = 'pending_provider';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case REQUIRES_RECONCILIATION = 'requires_reconciliation';
}
