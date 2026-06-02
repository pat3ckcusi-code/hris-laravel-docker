<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('leave_balances', 'user_id')) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
        }

        $foreignKey = DB::selectOne("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_balances' AND COLUMN_NAME = 'EmpNo' AND REFERENCED_TABLE_NAME IS NOT NULL");
        if ($foreignKey && isset($foreignKey->CONSTRAINT_NAME)) {
            DB::statement("ALTER TABLE leave_balances DROP FOREIGN KEY `{$foreignKey->CONSTRAINT_NAME}`");
        }

        DB::statement("UPDATE leave_balances lb JOIN users u ON lb.EmpNo = u.EmpNo SET lb.user_id = u.id");

        $userForeignKey = DB::selectOne("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_balances' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'");
        if (!$userForeignKey) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }

        $uniqueIndex = DB::selectOne("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'leave_balances' AND index_name = 'leave_balances_empno_unique'");
        if ($uniqueIndex) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->dropUnique('leave_balances_empno_unique');
            });
        }

        if (Schema::hasColumn('leave_balances', 'EmpNo')) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->dropColumn('EmpNo');
            });
        }

        if (Schema::hasColumn('leave_balances', 'user_id')) {
            $nullCount = DB::table('leave_balances')->whereNull('user_id')->count();
            if ($nullCount === 0) {
                DB::statement('ALTER TABLE leave_balances MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $userForeignKey = DB::selectOne("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_balances' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'");
        if ($userForeignKey && isset($userForeignKey->CONSTRAINT_NAME)) {
            DB::statement("ALTER TABLE leave_balances DROP FOREIGN KEY `{$userForeignKey->CONSTRAINT_NAME}`");
        }

        if (!Schema::hasColumn('leave_balances', 'EmpNo')) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->string('EmpNo')->after('id');
            });
        }

        DB::statement("UPDATE leave_balances lb JOIN users u ON lb.user_id = u.id SET lb.EmpNo = u.EmpNo");

        $uniqueIndex = DB::selectOne("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'leave_balances' AND index_name = 'leave_balances_empno_unique'");
        if (!$uniqueIndex) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->unique('EmpNo');
            });
        }

        $empNoForeignKey = DB::selectOne("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_balances' AND COLUMN_NAME = 'EmpNo' AND REFERENCED_TABLE_NAME = 'users'");
        if (!$empNoForeignKey) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->foreign('EmpNo')
                    ->references('EmpNo')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasColumn('leave_balances', 'user_id')) {
            Schema::table('leave_balances', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
    }
};
