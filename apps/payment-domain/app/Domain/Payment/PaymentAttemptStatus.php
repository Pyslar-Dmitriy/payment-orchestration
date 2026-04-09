<?php

namespace App\Domain\Payment;

enum PaymentAttemptStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
