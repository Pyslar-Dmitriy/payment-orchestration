<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('callback_deliveries', 'merchant_callback_deliveries');

        Schema::table('merchant_callback_deliveries', function (Blueprint $table) {
            // subscription_id is nullable so delivery history survives subscription deletion
            $table->uuid('subscription_id')->nullable()->after('id');
            $table->foreign('subscription_id')
                ->references('id')
                ->on('merchant_callback_subscriptions')
                ->nullOnDelete();

            // payment_id is nullable — some event types (e.g. merchant.updated) have no payment
            $table->char('payment_id', 26)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_callback_deliveries', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropColumn('subscription_id');
        });

        Schema::rename('merchant_callback_deliveries', 'callback_deliveries');
    }
};
