<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop dedup unique constraint before renaming so we can use the original
        // table name to reference the constraint, which PostgreSQL retains after rename.
        Schema::table('raw_webhooks', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'event_id']);
            $table->dropColumn('enqueued_at');
        });

        Schema::rename('raw_webhooks', 'webhook_events_raw');

        Schema::table('webhook_events_raw', function (Blueprint $table): void {
            $table->renameColumn('created_at', 'received_at');
            $table->string('processing_state')->default('received');
            $table->index('processing_state');
        });

        Schema::create('webhook_dedup', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('event_id');
            $table->uuid('raw_event_id');
            $table->timestamp('created_at');

            $table->unique(['provider', 'event_id']);
            $table->index('raw_event_id');
        });

        Schema::create('webhook_processing_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('raw_event_id')->index();
            $table->string('state');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_processing_attempts');
        Schema::dropIfExists('webhook_dedup');

        Schema::table('webhook_events_raw', function (Blueprint $table): void {
            $table->dropIndex('webhook_events_raw_processing_state_index');
            $table->dropColumn('processing_state');
            $table->renameColumn('received_at', 'created_at');
        });

        Schema::rename('webhook_events_raw', 'raw_webhooks');

        Schema::table('raw_webhooks', function (Blueprint $table): void {
            $table->timestamp('enqueued_at')->nullable();
            $table->unique(['provider', 'event_id']);
        });
    }
};
