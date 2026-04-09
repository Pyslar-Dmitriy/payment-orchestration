<?php

namespace App\Domain\Payment;

enum PaymentStatus: string
{
    case CREATED = 'created';
    case PENDING_PROVIDER = 'pending_provider';
    case REQUIRES_ACTION = 'requires_action';
    case AUTHORIZED = 'authorized';
    case CAPTURED = 'captured';
    case REFUNDING = 'refunding';
    case REFUNDED = 'refunded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REQUIRES_RECONCILIATION = 'requires_reconciliation'; // ADR-010: compensation for permanent workflow failures
}
