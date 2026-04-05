<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('published_at')->nullable()->index(); // null = pending
            $table->timestamp('created_at');

            $table->index(['published_at', 'created_at']); // polling index
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
