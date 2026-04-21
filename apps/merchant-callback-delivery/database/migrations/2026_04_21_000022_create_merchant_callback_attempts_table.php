<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — one row per HTTP call made for a delivery.
        // Exists so the full attempt trail is preserved even after the delivery is marked delivered or dlq.
        Schema::create('merchant_callback_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained('merchant_callback_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');   // 1-based, incremented per delivery
            $table->timestamp('attempted_at');
            $table->unsignedSmallInteger('http_status_code')->nullable(); // null on network/timeout failure
            $table->text('response_body')->nullable();        // first 4KB of response, truncated
            $table->json('response_headers')->nullable();
            $table->string('failure_reason')->nullable();     // null on success; see FailureReason enum
            $table->unsignedInteger('duration_ms')->nullable(); // wall-clock time of the HTTP call
            $table->timestamp('created_at');                  // immutable — no updated_at

            $table->unique(['delivery_id', 'attempt_number']);
            $table->index(['delivery_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_callback_attempts');
    }
};
