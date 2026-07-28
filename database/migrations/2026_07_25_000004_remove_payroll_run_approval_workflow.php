<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function foreignKeyExists(string $table, string $column): bool
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        $result = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table, $column]
        );

        return $result !== null;
    }

    public function up(): void
    {
        Schema::dropIfExists('approval_logs');

        if (Schema::hasColumn('payroll_runs', 'approved_by')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                if ($this->foreignKeyExists('payroll_runs', 'approved_by')) {
                    $table->dropForeign(['approved_by']);
                }
                $table->dropColumn('approved_by');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payroll_runs', 'approved_by')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('approval_logs')) {
            Schema::create('approval_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
                $table->string('status');
                $table->timestamp('actioned_at')->nullable();
                $table->timestamps();
            });
        }
    }
};
