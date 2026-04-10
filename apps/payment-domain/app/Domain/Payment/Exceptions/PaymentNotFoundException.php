<?php

namespace App\Domain\Payment\Exceptions;

use DomainException;

final class PaymentNotFoundException extends DomainException
{
    public function __construct(string $paymentId)
    {
        parent::__construct("Payment '{$paymentId}' not found.");
    }
}
