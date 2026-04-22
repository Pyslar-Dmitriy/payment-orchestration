<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE merchant_callback_deliveries ALTER COLUMN payment_id TYPE uuid USING payment_id::uuid');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE merchant_callback_deliveries ALTER COLUMN payment_id TYPE char(26) USING payment_id::char');
    }
};
