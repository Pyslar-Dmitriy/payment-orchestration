<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_inbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('message_id')->unique(); // broker-assigned ID; used for deduplication
            $table->string('message_type');
            $table->json('payload');
            $table->timestamp('processed_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_inbox_messages');
    }
};
