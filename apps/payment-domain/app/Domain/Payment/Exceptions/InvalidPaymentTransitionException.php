<?php

namespace App\Domain\Payment\Exceptions;

use App\Domain\Payment\PaymentStatus;
use DomainException;

final class InvalidPaymentTransitionException extends DomainException
{
    public function __construct(PaymentStatus $from, PaymentStatus $to)
    {
        parent::__construct(
            "Invalid payment status transition from '{$from->value}' to '{$to->value}'."
        );
    }
}
