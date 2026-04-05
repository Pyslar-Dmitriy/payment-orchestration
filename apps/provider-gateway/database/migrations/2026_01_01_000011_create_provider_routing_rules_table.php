<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->char('currency', 3)->nullable(); // null = any currency
            $table->unsignedBigInteger('min_amount')->nullable(); // in smallest unit
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['currency', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_routing_rules');
    }
};