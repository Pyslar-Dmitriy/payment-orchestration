<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostRefundEntriesTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/postings/refund';

    // -------------------------------------------------------------------------
    // Happy path — no fee refund
    // -------------------------------------------------------------------------

    public function test_posts_refund_entries_without_fee_refund(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'transaction_id',
            'idempotency_key',
            'entry_type',
            'payment_id',
            'refund_id',
            'created_at',
            'entries' => [['id', 'account_id', 'direction', 'amount', 'currency']],
        ]);
        $response->assertJsonFragment(['entry_type' => 'refund']);
        $response->assertJsonFragment(['idempotency_key' => 'refund:01REFUND1BXHF9MEVV1A2K3J4R']);
        $response->assertJsonFragment(['refund_id' => '01REFUND1BXHF9MEVV1A2K3J4R']);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_posts_refund_entries_creates_correct_debit_and_credit(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => 10000]))->assertStatus(201);

        $entries = LedgerEntry::all();
        $debit = $entries->firstWhere('direction', EntryDirection::Debit);
        $credit = $entries->firstWhere('direction', EntryDirection::Credit);

        $this->assertNotNull($debit);
        $this->assertNotNull($credit);
        $this->assertEquals(10000, $debit->amount);
        $this->assertEquals(10000, $credit->amount);
    }

    // -------------------------------------------------------------------------
    // Happy path — with fee refund (3-leg)
    // -------------------------------------------------------------------------

    public function test_posts_refund_entries_with_fee_refund_creates_three_legs(): void
    {
        $payload = $this->validPayload(['amount' => 10000, 'fee_refund_amount' => 300]);

        $response = $this->postJson(self::ENDPOINT, $payload);

        $response->assertStatus(201);
        $this->assertDatabaseCount('ledger_entries', 3);
    }

    public function test_refund_with_fee_refund_transaction_is_balanced(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => 10000, 'fee_refund_amount' => 300]));

        $transactionId = LedgerTransaction::first()->id;
        $entries = LedgerEntry::where('transaction_id', $transactionId)->get();

        $totalDebits = $entries->where('direction', EntryDirection::Debit)->sum('amount');
        $totalCredits = $entries->where('direction', EntryDirection::Credit)->sum('amount');

        $this->assertEquals($totalDebits, $totalCredits);
    }

    public function test_refund_without_fee_refund_transaction_is_balanced(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => 5000]));

        $transactionId = LedgerTransaction::first()->id;
        $entries = LedgerEntry::where('transaction_id', $transactionId)->get();

        $totalDebits = $entries->where('direction', EntryDirection::Debit)->sum('amount');
        $totalCredits = $entries->where('direction', EntryDirection::Credit)->sum('amount');

        $this->assertEquals($totalDebits, $totalCredits);
    }

    public function test_refund_with_fee_refund_merchant_debit_is_net_of_fee(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => 10000, 'fee_refund_amount' => 300]));

        $entries = LedgerEntry::all();
        $debits = $entries->where('direction', EntryDirection::Debit)->values();
        $credit = $entries->firstWhere('direction', EntryDirection::Credit);

        $this->assertCount(2, $debits);
        $this->assertEquals(10000, $credit->amount);

        $debitAmounts = $debits->pluck('amount')->sort()->values()->all();
        $this->assertEquals([300, 9700], $debitAmounts);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_duplicate_request_returns_200_without_creating_new_records(): void
    {
        $payload = $this->validPayload();

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(201);
        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_duplicate_request_returns_same_transaction_id(): void
    {
        $payload = $this->validPayload();

        $first = $this->postJson(self::ENDPOINT, $payload);
        $second = $this->postJson(self::ENDPOINT, $payload);

        $this->assertEquals($first->json('transaction_id'), $second->json('transaction_id'));
    }

    public function test_different_refund_id_creates_separate_transaction(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['refund_id' => '01REFUND1BXHF9MEVV1A2K3J4R']))->assertStatus(201);
        $this->postJson(self::ENDPOINT, $this->validPayload(['refund_id' => '01REFUND2BXHF9MEVV1A2K3J4S']))->assertStatus(201);

        $this->assertDatabaseCount('ledger_transactions', 2);
    }

    public function test_refund_does_not_corrupt_prior_capture_state(): void
    {
        $this->postJson('/api/postings/capture', [
            'payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ',
            'merchant_id' => 'merchant-abc',
            'amount' => 10000,
            'currency' => 'USD',
            'correlation_id' => '00000000-0000-0000-0000-000000000001',
        ])->assertStatus(201);

        $this->postJson(self::ENDPOINT, $this->validPayload())->assertStatus(201);

        $this->assertDatabaseCount('ledger_transactions', 2);
        $capture = LedgerTransaction::where('entry_type', 'capture')->first();
        $refund = LedgerTransaction::where('entry_type', 'refund')->first();
        $this->assertNotNull($capture);
        $this->assertNotNull($refund);
        $this->assertEquals(2, LedgerEntry::where('transaction_id', $capture->id)->count());
        $this->assertEquals(2, LedgerEntry::where('transaction_id', $refund->id)->count());
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_missing_refund_id_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['refund_id']);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(422)->assertJsonValidationErrors('refund_id');
    }

    public function test_missing_payment_id_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['payment_id']);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(422)->assertJsonValidationErrors('payment_id');
    }

    public function test_missing_merchant_id_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['merchant_id']);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(422)->assertJsonValidationErrors('merchant_id');
    }

    public function test_missing_amount_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['amount']);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_amount_zero_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_amount_negative_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_missing_currency_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['currency']);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_currency_wrong_length_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['currency' => 'US']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    }

    public function test_missing_correlation_id_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['correlation_id']);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(422)->assertJsonValidationErrors('correlation_id');
    }

    public function test_invalid_correlation_id_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['correlation_id' => 'not-a-uuid']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('correlation_id');
    }

    public function test_fee_refund_equal_to_amount_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => 1000, 'fee_refund_amount' => 1000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_refund_amount');
    }

    public function test_fee_refund_greater_than_amount_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['amount' => 1000, 'fee_refund_amount' => 1001]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_refund_amount');
    }

    public function test_negative_fee_refund_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['fee_refund_amount' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_refund_amount');
    }

    public function test_fee_refund_zero_is_accepted(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['fee_refund_amount' => 0]))->assertStatus(201);
    }

    public function test_invalid_causation_id_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['causation_id' => 'bad-id']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('causation_id');
    }

    // -------------------------------------------------------------------------
    // Account auto-creation
    // -------------------------------------------------------------------------

    public function test_posting_creates_escrow_and_merchant_accounts_if_absent(): void
    {
        $this->assertDatabaseCount('ledger_accounts', 0);

        $this->postJson(self::ENDPOINT, $this->validPayload())->assertStatus(201);

        $this->assertDatabaseCount('ledger_accounts', 2);
        $this->assertDatabaseHas('ledger_accounts', ['type' => 'escrow',   'owner_id' => 'platform']);
        $this->assertDatabaseHas('ledger_accounts', ['type' => 'merchant', 'owner_id' => 'merchant-abc']);
    }

    public function test_posting_with_fee_refund_creates_fees_account(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['fee_refund_amount' => 100]))->assertStatus(201);

        $this->assertDatabaseHas('ledger_accounts', ['type' => 'fees', 'owner_id' => 'platform']);
    }

    public function test_repeated_posting_reuses_existing_accounts(): void
    {
        $payload = $this->validPayload();

        $this->postJson(self::ENDPOINT, $payload);
        $this->postJson(self::ENDPOINT, $payload);

        $this->assertDatabaseCount('ledger_accounts', 2);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'refund_id' => '01REFUND1BXHF9MEVV1A2K3J4R',
            'payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ',
            'merchant_id' => 'merchant-abc',
            'amount' => 10000,
            'currency' => 'USD',
            'correlation_id' => '00000000-0000-0000-0000-000000000002',
        ], $overrides);
    }
}
