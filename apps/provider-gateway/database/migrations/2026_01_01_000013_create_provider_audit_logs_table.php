<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider_key');
            $table->string('operation'); // authorize, capture, refund, query_payment_status, query_refund_status
            $table->uuid('payment_uuid')->nullable()->index();
            $table->uuid('refund_uuid')->nullable()->index();
            $table->string('correlation_id')->index();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->string('outcome'); // success, hard_failure, transient_failure, exception
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_audit_logs');
    }
};
