<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payment_summaries', function (Blueprint $table) {
            $table->uuid('merchant_id')->primary();
            $table->unsignedBigInteger('total_count')->default(0);
            $table->unsignedBigInteger('captured_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('refunded_count')->default(0);
            $table->unsignedBigInteger('cancelled_count')->default(0);
            $table->unsignedBigInteger('total_volume_cents')->default(0);
            $table->unsignedBigInteger('captured_volume_cents')->default(0);
            $table->unsignedBigInteger('refunded_volume_cents')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payment_summaries');
    }
};
