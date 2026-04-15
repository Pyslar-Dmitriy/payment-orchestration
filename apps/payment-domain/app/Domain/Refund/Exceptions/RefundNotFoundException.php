<?php

namespace App\Domain\Refund\Exceptions;

use RuntimeException;

final class RefundNotFoundException extends RuntimeException
{
    public function __construct(string $refundId)
    {
        parent::__construct("Refund '{$refundId}' not found.");
    }
}
