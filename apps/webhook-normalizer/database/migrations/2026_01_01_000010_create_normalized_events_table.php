<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalized_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('raw_webhook_id')->index(); // reference to webhook-ingest — no cross-service FK
            $table->string('provider')->index();
            $table->string('event_type')->index(); // internal canonical event type
            $table->char('payment_id', 26)->nullable()->index(); // resolved payment ULID
            $table->string('provider_transaction_id')->nullable();
            $table->json('payload'); // normalized, provider-agnostic payload
            $table->uuid('correlation_id')->index();
            $table->timestamp('published_at')->nullable()->index(); // null = pending outbox publish
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalized_events');
    }
};
