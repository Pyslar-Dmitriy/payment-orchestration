<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_performance_summaries', function (Blueprint $table) {
            $table->string('provider_id')->primary();
            $table->unsignedBigInteger('total_attempts')->default(0);
            $table->unsignedBigInteger('authorized_count')->default(0);
            $table->unsignedBigInteger('captured_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_performance_summaries');
    }
};
