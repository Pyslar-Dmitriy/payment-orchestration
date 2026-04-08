<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('payment_id', 26)->index(); // reference only — no cross-service FK
            $table->uuid('merchant_id')->index();
            $table->unsignedBigInteger('amount'); // in smallest currency unit (e.g. cents)
            $table->char('currency', 3);
            $table->string('status'); // pending|succeeded|failed
            $table->uuid('correlation_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
