<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('customer_reference')->nullable()->after('external_reference');
            $table->string('payment_method_reference')->nullable()->after('customer_reference');
            $table->json('metadata')->nullable()->after('payment_method_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['customer_reference', 'payment_method_reference', 'metadata']);
        });
    }
};