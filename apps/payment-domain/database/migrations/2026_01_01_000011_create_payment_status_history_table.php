<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_status_history', function (Blueprint $table) {
            $table->id();
            $table->char('payment_id', 26)->index();
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->nullable();
            $table->uuid('correlation_id');
            $table->uuid('causation_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_status_history');
    }
};