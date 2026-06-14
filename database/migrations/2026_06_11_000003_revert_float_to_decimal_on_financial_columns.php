<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // leave_balances: float(8,2) → decimal(10,3)
        Schema::table('leave_balances', function (Blueprint $table) {
            foreach (['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'] as $col) {
                if (Schema::hasColumn('leave_balances', $col)) {
                    $table->decimal($col, 10, 3)->default(0)->change();
                }
            }
        });

        // leave_requests: float(8,2) → decimal(10,3)
        Schema::table('leave_requests', function (Blueprint $table) {
            $nullable = [
                'total_days',
                'balance_vacation_leave',
                'balance_sick_leave',
                'balance_wellness_leave',
                'balance_solo_parent_leave',
                'balance_special_leave_privilege',
                'lwop_days',
            ];
            foreach ($nullable as $col) {
                if (Schema::hasColumn('leave_requests', $col)) {
                    $table->decimal($col, 10, 3)->nullable()->change();
                }
            }
            if (Schema::hasColumn('leave_requests', 'paid_days')) {
                $table->decimal('paid_days', 10, 3)->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            foreach (['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'] as $col) {
                if (Schema::hasColumn('leave_balances', $col)) {
                    $table->float($col, 8, 2)->default(0)->change();
                }
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $nullable = [
                'total_days',
                'balance_vacation_leave',
                'balance_sick_leave',
                'balance_wellness_leave',
                'balance_solo_parent_leave',
                'balance_special_leave_privilege',
                'lwop_days',
            ];
            foreach ($nullable as $col) {
                if (Schema::hasColumn('leave_requests', $col)) {
                    $table->float($col, 8, 2)->nullable()->change();
                }
            }
            if (Schema::hasColumn('leave_requests', 'paid_days')) {
                $table->float('paid_days', 8, 2)->default(0)->change();
            }
        });
    }
};
