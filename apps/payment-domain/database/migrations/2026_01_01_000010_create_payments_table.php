<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->uuid('merchant_id')->index(); // reference only — no cross-service FK
            $table->string('external_reference')->index(); // merchant's order ID
            $table->unsignedBigInteger('amount'); // in smallest currency unit (e.g. cents)
            $table->char('currency', 3);
            $table->string('status'); // initiated|authorizing|authorized|capturing|captured|refunding|refunded|failed|cancelled
            $table->string('provider_id')->nullable()->index();
            $table->string('provider_transaction_id')->nullable();
            $table->uuid('correlation_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};