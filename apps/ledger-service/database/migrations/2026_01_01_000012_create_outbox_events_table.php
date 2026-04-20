<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — no updated_at, no soft deletes, no mutations after insert.
        // Individual debit or credit line within a ledger_transaction.
        // A valid transaction has sum(debits) = sum(credits) across its entries.
        // Balance for an account = sum(credits) - sum(debits) over all its entries.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID — sortable by creation time
            $table->char('transaction_id', 26);
            $table->foreign('transaction_id')->references('id')->on('ledger_transactions');
            $table->foreignUuid('account_id')->constrained('ledger_accounts');
            $table->string('direction'); // debit | credit
            $table->unsignedBigInteger('amount'); // always positive, in smallest currency unit
            $table->char('currency', 3);
            $table->timestamp('created_at'); // immutable — no updated_at

            $table->index(['account_id', 'created_at']); // for balance derivation queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
