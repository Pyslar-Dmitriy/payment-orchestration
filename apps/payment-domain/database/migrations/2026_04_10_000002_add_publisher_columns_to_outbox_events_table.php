<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->smallInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->boolean('failed_permanently')->default(false);
        });

        DB::statement(
            'CREATE INDEX outbox_events_pending_idx ON outbox_events (created_at ASC) '
            .'WHERE published_at IS NULL AND failed_permanently = FALSE'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_events_pending_idx');

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn(['failed_permanently', 'last_error', 'retry_count']);
        });
    }
};
