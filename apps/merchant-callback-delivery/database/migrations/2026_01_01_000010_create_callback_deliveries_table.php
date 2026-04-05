<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callback_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('payment_id', 26)->index(); // reference only — no cross-service FK
            $table->uuid('merchant_id')->index();
            $table->string('event_type');
            $table->json('payload');
            $table->string('endpoint_url');
            $table->string('status')->default('pending')->index(); // pending|delivered|failed|dlq
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index(); // for retry scheduling
            $table->timestamp('delivered_at')->nullable();
            $table->uuid('correlation_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_deliveries');
    }
};