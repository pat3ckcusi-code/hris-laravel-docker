<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE work_suspensions MODIFY reason TEXT NOT NULL');
        DB::statement('ALTER TABLE deductions MODIFY description TEXT NULL');
        DB::statement('ALTER TABLE deductions MODIFY formula TEXT NULL');
        DB::statement('ALTER TABLE earnings MODIFY description TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_suspensions MODIFY reason VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE deductions MODIFY description VARCHAR(255) NULL');
        DB::statement('ALTER TABLE deductions MODIFY formula VARCHAR(255) NULL');
        DB::statement('ALTER TABLE earnings MODIFY description VARCHAR(255) NULL');
    }
};
