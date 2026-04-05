<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider')->index();
            $table->string('event_id'); // provider's own event identifier
            $table->json('headers');
            $table->text('payload'); // raw request body
            $table->boolean('signature_verified')->default(false);
            $table->uuid('correlation_id')->index();
            $table->timestamp('enqueued_at')->nullable(); // set after RabbitMQ publish
            $table->timestamp('created_at');

            $table->unique(['provider', 'event_id']); // deduplication constraint
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_webhooks');
    }
};
