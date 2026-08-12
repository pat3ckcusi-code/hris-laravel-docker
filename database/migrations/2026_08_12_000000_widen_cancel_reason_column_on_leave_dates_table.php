<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE leave_dates MODIFY cancel_reason TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leave_dates MODIFY cancel_reason VARCHAR(255) NULL');
    }
};
