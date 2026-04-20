<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum EntryType: string
{
    case Authorization = 'authorization'; // funds placed in escrow on auth
    case Capture = 'capture';       // funds moved from escrow to merchant
    case Refund = 'refund';        // funds returned from merchant to escrow/provider
    case Fee = 'fee';           // platform fee deducted from merchant
    case Reversal = 'reversal';      // void of a previously authorized hold
}
