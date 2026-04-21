<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_callback_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->string('callback_url');
            $table->string('signing_secret')->nullable();    // HMAC secret for request signature
            $table->string('signing_algorithm')->nullable(); // e.g. hmac-sha256
            $table->json('event_types');                     // ["payment.captured", "refund.completed", ...]
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['merchant_id', 'callback_url']); // one subscription per URL per merchant
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_callback_subscriptions');
    }
};
