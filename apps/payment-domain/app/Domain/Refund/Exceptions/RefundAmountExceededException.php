<?php

namespace App\Domain\Refund\Exceptions;

use DomainException;

final class RefundAmountExceededException extends DomainException
{
    public function __construct(int $requested, int $remaining)
    {
        parent::__construct(
            "Refund amount {$requested} exceeds the remaining refundable amount {$remaining}."
        );
    }
}
