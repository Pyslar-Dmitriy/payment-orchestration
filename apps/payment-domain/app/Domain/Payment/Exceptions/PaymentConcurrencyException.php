<?php

namespace App\Domain\Payment\Exceptions;

use DomainException;

final class PaymentConcurrencyException extends DomainException
{
    public function __construct(string $paymentId, int $expectedVersion)
    {
        parent::__construct(
            "Concurrency conflict on payment '{$paymentId}': expected version {$expectedVersion} was already modified."
        );
    }
}
