<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\EntryType;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerTransaction;
use App\Infrastructure\Outbox\OutboxMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerModelTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // LedgerAccount
    // -------------------------------------------------------------------------

    public function test_can_create_ledger_account(): void
    {
        $account = LedgerAccount::create([
            'type' => AccountType::Merchant,
            'owner_id' => 'merchant-uuid-1',
            'currency' => 'USD',
        ]);

        $this->assertDatabaseHas('ledger_accounts', [
            'type' => 'merchant',
            'owner_id' => 'merchant-uuid-1',
            'currency' => 'USD',
        ]);
        $this->assertNotEmpty($account->id);
    }

    public function test_ledger_account_enforces_unique_type_owner_currency(): void
    {
        LedgerAccount::create(['type' => AccountType::Merchant, 'owner_id' => 'mid-1', 'currency' => 'USD']);

        $this->expectException(QueryException::class);

        LedgerAccount::create(['type' => AccountType::Merchant, 'owner_id' => 'mid-1', 'currency' => 'USD']);
    }

    public function test_same_owner_can_have_accounts_in_different_currencies(): void
    {
        LedgerAccount::create(['type' => AccountType::Merchant, 'owner_id' => 'mid-1', 'currency' => 'USD']);
        LedgerAccount::create(['type' => AccountType::Merchant, 'owner_id' => 'mid-1', 'currency' => 'EUR']);

        $this->assertDatabaseCount('ledger_accounts', 2);
    }

    public function test_platform_escrow_account_uses_platform_owner_id(): void
    {
        $account = LedgerAccount::create([
            'type' => AccountType::Escrow,
            'owner_id' => 'platform',
            'currency' => 'USD',
        ]);

        $this->assertEquals('escrow', $account->type->value);
        $this->assertEquals('platform', $account->owner_id);
    }

    // -------------------------------------------------------------------------
    // LedgerTransaction
    // -------------------------------------------------------------------------

    public function test_can_create_ledger_transaction(): void
    {
        $tx = LedgerTransaction::create([
            'entry_type' => EntryType::Capture,
            'payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ',
            'idempotency_key' => 'capture:01HV5E1BXHF9MEVV1A2K3J4YZQ:corr-123',
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $this->assertDatabaseHas('ledger_transactions', [
            'entry_type' => 'capture',
            'payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ',
            'idempotency_key' => 'capture:01HV5E1BXHF9MEVV1A2K3J4YZQ:corr-123',
        ]);
        $this->assertNull($tx->updated_at);
    }

    public function test_ledger_transaction_idempotency_key_is_unique(): void
    {
        LedgerTransaction::create([
            'entry_type' => EntryType::Capture,
            'idempotency_key' => 'capture:pay-1:corr-1',
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $this->expectException(QueryException::class);

        LedgerTransaction::create([
            'entry_type' => EntryType::Capture,
            'idempotency_key' => 'capture:pay-1:corr-1',
            'correlation_id' => Str::uuid()->toString(),
        ]);
    }

    public function test_ledger_transaction_has_no_updated_at(): void
    {
        $tx = LedgerTransaction::create([
            'entry_type' => EntryType::Refund,
            'idempotency_key' => 'refund:ref-1:corr-1',
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $this->assertNull($tx->updated_at);
        $this->assertArrayNotHasKey('updated_at', $tx->toArray());
    }

    // -------------------------------------------------------------------------
    // LedgerEntry — double-entry balancing
    // -------------------------------------------------------------------------

    public function test_can_post_double_entry_for_capture(): void
    {
        [$escrow, $merchant] = $this->makeEscrowAndMerchantAccounts('USD');

        $tx = $this->makeTransaction(EntryType::Capture, 'pay-1', 'corr-1');

        // Debit escrow (reduce hold), credit merchant (funds available)
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $escrow->id,  'direction' => EntryDirection::Debit,  'amount' => 10000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $merchant->id, 'direction' => EntryDirection::Credit, 'amount' => 10000, 'currency' => 'USD']);

        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_balance_can_be_derived_from_entries(): void
    {
        [$escrow, $merchant] = $this->makeEscrowAndMerchantAccounts('USD');

        $tx = $this->makeTransaction(EntryType::Capture, 'pay-2', 'corr-2');

        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $escrow->id,  'direction' => EntryDirection::Debit,  'amount' => 10000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $merchant->id, 'direction' => EntryDirection::Credit, 'amount' => 10000, 'currency' => 'USD']);

        $this->assertEquals(-10000, $escrow->balance());  // debit-normal: funds released
        $this->assertEquals(10000, $merchant->balance()); // credit-normal: funds receivable
    }

    public function test_multi_leg_transaction_models_fee_split(): void
    {
        $escrow = LedgerAccount::create(['type' => AccountType::Escrow,   'owner_id' => 'platform',  'currency' => 'USD']);
        $merchant = LedgerAccount::create(['type' => AccountType::Merchant, 'owner_id' => 'mid-1',     'currency' => 'USD']);
        $fees = LedgerAccount::create(['type' => AccountType::Fees,     'owner_id' => 'platform',  'currency' => 'USD']);

        $tx = $this->makeTransaction(EntryType::Capture, 'pay-3', 'corr-3');

        // Capture 10000: merchant gets 9700, platform fee 300
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $escrow->id,   'direction' => EntryDirection::Debit,  'amount' => 10000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $merchant->id, 'direction' => EntryDirection::Credit, 'amount' => 9700,  'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $fees->id,     'direction' => EntryDirection::Credit, 'amount' => 300,   'currency' => 'USD']);

        $this->assertEquals(-10000, $escrow->balance());
        $this->assertEquals(9700, $merchant->balance());
        $this->assertEquals(300, $fees->balance());

        // Verify total debits equal total credits (balanced transaction)
        $totalDebits = $tx->entries()->where('direction', 'debit')->sum('amount');
        $totalCredits = $tx->entries()->where('direction', 'credit')->sum('amount');
        $this->assertEquals($totalDebits, $totalCredits);
    }

    public function test_can_represent_refund_posting(): void
    {
        [$escrow, $merchant] = $this->makeEscrowAndMerchantAccounts('USD');

        // First post a capture
        $captureTx = $this->makeTransaction(EntryType::Capture, 'pay-4', 'corr-4');
        LedgerEntry::create(['transaction_id' => $captureTx->id, 'account_id' => $escrow->id,  'direction' => EntryDirection::Debit,  'amount' => 5000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $captureTx->id, 'account_id' => $merchant->id, 'direction' => EntryDirection::Credit, 'amount' => 5000, 'currency' => 'USD']);

        // Then post a refund (reverse the capture direction)
        $refundTx = $this->makeTransaction(EntryType::Refund, 'pay-4', 'corr-5', refundId: 'ref-1');
        LedgerEntry::create(['transaction_id' => $refundTx->id, 'account_id' => $merchant->id, 'direction' => EntryDirection::Debit,  'amount' => 5000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $refundTx->id, 'account_id' => $escrow->id,  'direction' => EntryDirection::Credit, 'amount' => 5000, 'currency' => 'USD']);

        $this->assertEquals(0, $merchant->balance());
        $this->assertEquals(0, $escrow->balance());
    }

    public function test_ledger_entry_has_no_updated_at(): void
    {
        [$escrow] = $this->makeEscrowAndMerchantAccounts('USD');
        $tx = $this->makeTransaction(EntryType::Capture, 'pay-5', 'corr-6');

        $entry = LedgerEntry::create([
            'transaction_id' => $tx->id,
            'account_id' => $escrow->id,
            'direction' => EntryDirection::Debit,
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $this->assertNull($entry->updated_at);
    }

    public function test_ledger_entries_link_to_transaction(): void
    {
        [$escrow, $merchant] = $this->makeEscrowAndMerchantAccounts('USD');
        $tx = $this->makeTransaction(EntryType::Capture, 'pay-6', 'corr-7');

        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $escrow->id,  'direction' => EntryDirection::Debit,  'amount' => 2000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $merchant->id, 'direction' => EntryDirection::Credit, 'amount' => 2000, 'currency' => 'USD']);

        $this->assertCount(2, $tx->entries);
        $this->assertEquals($tx->id, $tx->entries->first()->transaction_id);
    }

    public function test_can_represent_authorization_posting(): void
    {
        $escrow = LedgerAccount::create(['type' => AccountType::Escrow,    'owner_id' => 'platform', 'currency' => 'USD']);
        $provider = LedgerAccount::create(['type' => AccountType::Provider,  'owner_id' => 'stripe',   'currency' => 'USD']);

        $tx = $this->makeTransaction(EntryType::Authorization, 'pay-7', 'corr-8');

        // Debit provider (funds committed), credit escrow (hold placed)
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $provider->id, 'direction' => EntryDirection::Debit,  'amount' => 8000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $tx->id, 'account_id' => $escrow->id,   'direction' => EntryDirection::Credit, 'amount' => 8000, 'currency' => 'USD']);

        $this->assertEquals(-8000, $provider->balance());
        $this->assertEquals(8000, $escrow->balance());

        $totalDebits = $tx->entries()->where('direction', 'debit')->sum('amount');
        $totalCredits = $tx->entries()->where('direction', 'credit')->sum('amount');
        $this->assertEquals($totalDebits, $totalCredits);
    }

    public function test_can_represent_reversal_posting(): void
    {
        $escrow = LedgerAccount::create(['type' => AccountType::Escrow,   'owner_id' => 'platform', 'currency' => 'USD']);
        $provider = LedgerAccount::create(['type' => AccountType::Provider, 'owner_id' => 'stripe',   'currency' => 'USD']);

        // First post an authorization
        $authTx = $this->makeTransaction(EntryType::Authorization, 'pay-8', 'corr-9');
        LedgerEntry::create(['transaction_id' => $authTx->id, 'account_id' => $provider->id, 'direction' => EntryDirection::Debit,  'amount' => 6000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $authTx->id, 'account_id' => $escrow->id,   'direction' => EntryDirection::Credit, 'amount' => 6000, 'currency' => 'USD']);

        // Then void it with a reversal (mirror of the auth)
        $reversalTx = $this->makeTransaction(EntryType::Reversal, 'pay-8', 'corr-10');
        LedgerEntry::create(['transaction_id' => $reversalTx->id, 'account_id' => $escrow->id,   'direction' => EntryDirection::Debit,  'amount' => 6000, 'currency' => 'USD']);
        LedgerEntry::create(['transaction_id' => $reversalTx->id, 'account_id' => $provider->id, 'direction' => EntryDirection::Credit, 'amount' => 6000, 'currency' => 'USD']);

        $this->assertEquals(0, $escrow->balance());
        $this->assertEquals(0, $provider->balance());
    }

    // -------------------------------------------------------------------------
    // OutboxMessage
    // -------------------------------------------------------------------------

    public function test_can_create_outbox_message(): void
    {
        OutboxMessage::create([
            'aggregate_type' => 'LedgerTransaction',
            'aggregate_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ',
            'event_type' => 'LedgerCapturePosted',
            'payload' => ['payment_id' => 'pay-1', 'amount' => 10000, 'currency' => 'USD'],
        ]);

        $this->assertDatabaseHas('outbox_messages', [
            'aggregate_type' => 'LedgerTransaction',
            'event_type' => 'LedgerCapturePosted',
            'published_at' => null,
            'retry_count' => 0,
            'failed_permanently' => false,
        ]);
    }

    public function test_outbox_message_has_no_updated_at(): void
    {
        $msg = OutboxMessage::create([
            'aggregate_type' => 'LedgerTransaction',
            'aggregate_id' => 'agg-1',
            'event_type' => 'LedgerCapturePosted',
            'payload' => [],
        ]);

        $this->assertNull($msg->updated_at);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{LedgerAccount, LedgerAccount} */
    private function makeEscrowAndMerchantAccounts(string $currency): array
    {
        return [
            LedgerAccount::create(['type' => AccountType::Escrow,   'owner_id' => 'platform', 'currency' => $currency]),
            LedgerAccount::create(['type' => AccountType::Merchant,  'owner_id' => 'mid-1',   'currency' => $currency]),
        ];
    }

    private function makeTransaction(
        EntryType $type,
        string $paymentId,
        string $correlationSuffix,
        ?string $refundId = null,
    ): LedgerTransaction {
        return LedgerTransaction::create([
            'entry_type' => $type,
            'payment_id' => $paymentId,
            'refund_id' => $refundId,
            'idempotency_key' => "{$type->value}:{$paymentId}:{$correlationSuffix}",
            'correlation_id' => Str::uuid()->toString(),
        ]);
    }
}
