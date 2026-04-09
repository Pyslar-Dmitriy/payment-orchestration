<?php

namespace App\Domain\Payment;

enum PaymentStatus: string
{
    case INITIATED = 'initiated';
    case AUTHORIZING = 'authorizing';
    case AUTHORIZED = 'authorized';
    case CAPTURING = 'capturing';
    case CAPTURED = 'captured';
    case REFUNDING = 'refunding';
    case REFUNDED = 'refunded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REQUIRES_RECONCILIATION = 'requires_reconciliation'; // ADR-010: compensation for permanent workflow failures
}
