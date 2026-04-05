<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('key_hash')->unique(); // hashed key — never store plaintext
            $table->string('key_prefix', 8); // first 8 chars for display/lookup hint
            $table->string('name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
