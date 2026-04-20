<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('published_at')->nullable()->index(); // null = pending publication
            $table->smallInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->boolean('failed_permanently')->default(false);
            $table->timestamp('created_at');

            $table->index(['published_at', 'created_at']); // polling index
        });

        // Partial index for efficient pending-message polling (PostgreSQL only).
        DB::statement(
            'CREATE INDEX outbox_messages_pending_idx ON outbox_messages (created_at ASC) '
            .'WHERE published_at IS NULL AND failed_permanently = FALSE'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_messages_pending_idx');
        Schema::dropIfExists('outbox_messages');
    }
};
