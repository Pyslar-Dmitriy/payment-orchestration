<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — no updated_at, no soft deletes, no mutations after insert.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID — sortable by creation time
            $table->foreignUuid('debit_account_id')->constrained('accounts');
            $table->foreignUuid('credit_account_id')->constrained('accounts');
            $table->unsignedBigInteger('amount'); // always positive, in smallest currency unit
            $table->char('currency', 3);
            $table->string('entry_type'); // authorization|capture|refund|fee|reversal
            $table->char('payment_id', 26)->nullable()->index(); // reference only — no cross-service FK
            $table->uuid('correlation_id')->index();
            $table->uuid('causation_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at'); // immutable — no updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};