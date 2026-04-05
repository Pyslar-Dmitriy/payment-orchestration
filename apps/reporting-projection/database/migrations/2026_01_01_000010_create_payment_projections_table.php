<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_projections', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // same as payment_id from payment-domain
            $table->uuid('merchant_id')->index();
            $table->string('external_reference')->nullable()->index();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->index();
            $table->string('status')->index();
            $table->string('provider_id')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_projections');
    }
};
