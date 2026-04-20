<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum AccountType: string
{
    case Merchant = 'merchant';   // asset — funds owed to the merchant
    case Provider = 'provider';   // liability — funds owed to/from the provider
    case Fees = 'fees';       // revenue — platform fee income
    case Escrow = 'escrow';     // asset — authorized but not yet captured funds
}
