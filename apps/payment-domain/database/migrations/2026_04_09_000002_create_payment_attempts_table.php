<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('payment_id', 26)->index(); // reference only — no cross-service FK
            $table->unsignedSmallInteger('attempt_number');
            $table->string('provider_id');
            $table->string('provider_transaction_id')->nullable();
            $table->string('status'); // pending|succeeded|failed
            $table->string('failure_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('provider_response')->nullable(); // raw provider reply for debugging
            $table->uuid('correlation_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
