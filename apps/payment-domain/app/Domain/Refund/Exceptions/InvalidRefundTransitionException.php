<?php

namespace App\Domain\Refund\Exceptions;

use App\Domain\Refund\RefundStatus;
use RuntimeException;

final class InvalidRefundTransitionException extends RuntimeException
{
    public function __construct(RefundStatus $from, RefundStatus $to)
    {
        parent::__construct(
            "Cannot transition refund from '{$from->value}' to '{$to->value}'."
        );
    }
}
