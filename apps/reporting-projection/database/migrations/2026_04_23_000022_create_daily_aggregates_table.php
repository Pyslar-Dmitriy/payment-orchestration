<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_aggregates', function (Blueprint $table) {
            $table->date('date');
            $table->char('currency', 3);
            $table->unsignedBigInteger('payments_initiated')->default(0);
            $table->unsignedBigInteger('payments_captured')->default(0);
            $table->unsignedBigInteger('payments_failed')->default(0);
            $table->unsignedBigInteger('payments_cancelled')->default(0);
            $table->unsignedBigInteger('volume_initiated_cents')->default(0);
            $table->unsignedBigInteger('volume_captured_cents')->default(0);
            $table->unsignedBigInteger('refunds_succeeded')->default(0);
            $table->unsignedBigInteger('refund_volume_cents')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->primary(['date', 'currency']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_aggregates');
    }
};
