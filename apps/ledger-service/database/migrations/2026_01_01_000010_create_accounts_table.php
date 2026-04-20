<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (type, owner_id, currency) combination.
        // type: merchant | provider | fees | escrow
        // owner_id: external identifier (merchant UUID, provider slug, 'platform' for system accounts)
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('owner_id')->index();
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['type', 'owner_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
