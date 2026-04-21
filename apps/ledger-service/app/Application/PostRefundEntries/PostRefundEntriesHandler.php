<?php

declare(strict_types=1);

namespace App\Application\PostRefundEntries;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\EntryType;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerTransaction;
use App\Infrastructure\Outbox\OutboxMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class PostRefundEntriesHandler
{
    public function handle(PostRefundEntriesCommand $command): LedgerTransaction
    {
        $idempotencyKey = "refund:{$command->refundId}";

        $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $escrowAccount = $this->findOrCreateAccount(AccountType::Escrow, 'platform', $command->currency);
        $merchantAccount = $this->findOrCreateAccount(AccountType::Merchant, $command->merchantId, $command->currency);
        $feesAccount = $command->feeRefundAmount > 0
            ? $this->findOrCreateAccount(AccountType::Fees, 'platform', $command->currency)
            : null;

        try {
            return DB::transaction(function () use ($command, $idempotencyKey, $escrowAccount, $merchantAccount, $feesAccount): LedgerTransaction {
                $transaction = LedgerTransaction::create([
                    'entry_type' => EntryType::Refund,
                    'payment_id' => $command->paymentId,
                    'refund_id' => $command->refundId,
                    'idempotency_key' => $idempotencyKey,
                    'correlation_id' => $command->correlationId,
                    'causation_id' => $command->causationId,
                ]);

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $merchantAccount->id,
                    'direction' => EntryDirection::Debit,
                    'amount' => $command->amount - $command->feeRefundAmount,
                    'currency' => $command->currency,
                ]);

                if ($feesAccount !== null) {
                    LedgerEntry::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $feesAccount->id,
                        'direction' => EntryDirection::Debit,
                        'amount' => $command->feeRefundAmount,
                        'currency' => $command->currency,
                    ]);
                }

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $escrowAccount->id,
                    'direction' => EntryDirection::Credit,
                    'amount' => $command->amount,
                    'currency' => $command->currency,
                ]);

                $transaction->load('entries.account');

                OutboxMessage::create([
                    'aggregate_type' => 'LedgerTransaction',
                    'aggregate_id' => $transaction->id,
                    'event_type' => 'ledger.entry_posted.v1',
                    'payload' => [
                        'entry_id' => $transaction->id,
                        'payment_id' => $transaction->payment_id,
                        'refund_id' => $transaction->refund_id,
                        'merchant_id' => $command->merchantId,
                        'posting_type' => $transaction->entry_type->value,
                        'lines' => $transaction->entries->map(fn (LedgerEntry $e) => [
                            'account' => "{$e->account->type->value}.{$e->account->owner_id}",
                            'direction' => $e->direction->value,
                            'amount' => ['value' => $e->amount, 'currency' => $e->currency],
                        ])->values()->all(),
                        'idempotency_key' => $transaction->idempotency_key,
                        'posted_at' => $transaction->created_at->toIso8601String(),
                        'correlation_id' => $command->correlationId,
                        'causation_id' => $command->causationId,
                        'occurred_at' => $transaction->created_at->toIso8601String(),
                    ],
                ]);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            return LedgerTransaction::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    private function findOrCreateAccount(AccountType $type, string $ownerId, string $currency): LedgerAccount
    {
        try {
            return LedgerAccount::firstOrCreate(
                ['type' => $type->value, 'owner_id' => $ownerId, 'currency' => $currency],
            );
        } catch (UniqueConstraintViolationException) {
            return LedgerAccount::where('type', $type->value)
                ->where('owner_id', $ownerId)
                ->where('currency', $currency)
                ->firstOrFail();
        }
    }
}
