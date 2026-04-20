<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — no updated_at, no soft deletes, no mutations after insert.
        // Groups one or more double-entry lines for a single economic event (capture, refund, fee, etc.).
        // The set of entries belonging to a transaction must balance: sum(debits) = sum(credits).
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID — sortable by creation time
            $table->string('entry_type'); // authorization|capture|refund|fee|reversal
            $table->char('payment_id', 26)->nullable()->index();  // reference only — no cross-service FK
            $table->char('refund_id', 26)->nullable()->index();   // reference only — no cross-service FK
            $table->string('idempotency_key')->unique();          // prevents double-posting
            $table->uuid('correlation_id')->index();
            $table->uuid('causation_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at'); // immutable — no updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
