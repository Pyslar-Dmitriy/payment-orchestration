<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResetProjectionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedAllTables(): void
    {
        $paymentId = Str::ulid()->toString();
        $merchantId = Str::uuid()->toString();

        DB::table('inbox_messages')->insert([
            'message_id' => Str::uuid()->toString(),
            'processed_at' => now(),
            'created_at' => now(),
        ]);

        DB::table('payment_projections')->insert([
            'id' => $paymentId,
            'merchant_id' => $merchantId,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'captured',
        ]);

        DB::table('merchant_payment_summaries')->insert([
            'merchant_id' => $merchantId,
            'total_count' => 1,
            'updated_at' => now(),
        ]);

        DB::table('provider_performance_summaries')->insert([
            'provider_id' => 'mock-provider',
            'total_attempts' => 1,
            'updated_at' => now(),
        ]);

        DB::table('daily_aggregates')->insert([
            'date' => '2026-04-23',
            'currency' => 'USD',
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // --force flag bypasses confirmation
    // -----------------------------------------------------------------------

    public function test_clears_inbox_messages_with_force(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections --force')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('inbox_messages')->count());
    }

    public function test_clears_payment_projections_with_force(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections --force')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('payment_projections')->count());
    }

    public function test_clears_merchant_payment_summaries_with_force(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections --force')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('merchant_payment_summaries')->count());
    }

    public function test_clears_provider_performance_summaries_with_force(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections --force')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('provider_performance_summaries')->count());
    }

    public function test_clears_daily_aggregates_with_force(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections --force')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('daily_aggregates')->count());
    }

    public function test_all_tables_cleared_in_single_run(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections --force')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('inbox_messages')->count());
        $this->assertSame(0, DB::table('payment_projections')->count());
        $this->assertSame(0, DB::table('merchant_payment_summaries')->count());
        $this->assertSame(0, DB::table('provider_performance_summaries')->count());
        $this->assertSame(0, DB::table('daily_aggregates')->count());
    }

    // -----------------------------------------------------------------------
    // Confirmation prompt
    // -----------------------------------------------------------------------

    public function test_cancels_when_confirmation_declined(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections')
            ->expectsConfirmation('This will delete ALL projection data. Continue?', 'no')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('inbox_messages')->count());
        $this->assertSame(1, DB::table('payment_projections')->count());
    }

    public function test_clears_tables_when_confirmation_accepted(): void
    {
        $this->seedAllTables();

        $this->artisan('reporting:reset-projections')
            ->expectsConfirmation('This will delete ALL projection data. Continue?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('inbox_messages')->count());
        $this->assertSame(0, DB::table('payment_projections')->count());
    }

    // -----------------------------------------------------------------------
    // Idempotency — running reset on already-empty tables is safe
    // -----------------------------------------------------------------------

    public function test_succeeds_when_tables_are_already_empty(): void
    {
        $this->artisan('reporting:reset-projections --force')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('inbox_messages')->count());
    }
}
