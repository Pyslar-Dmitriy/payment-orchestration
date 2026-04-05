<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_type'); // merchant | provider | fees | escrow
            $table->string('owner_id')->index(); // external reference — no cross-service FK
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};