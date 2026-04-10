<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Convert several DECIMAL columns to FLOAT for the leave_requests table
     * using portable Schema builder instead of raw SQL.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'total_days')) {
                $table->float('total_days', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'paid_days')) {
                $table->float('paid_days', 8, 2)->default(0)->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_vacation_leave')) {
                $table->float('balance_vacation_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_sick_leave')) {
                $table->float('balance_sick_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_wellness_leave')) {
                $table->float('balance_wellness_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_solo_parent_leave')) {
                $table->float('balance_solo_parent_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_special_leave_privilege')) {
                $table->float('balance_special_leave_privilege', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'lwop_days')) {
                $table->float('lwop_days', 8, 2)->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'total_days')) {
                $table->decimal('total_days', 5, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'paid_days')) {
                $table->decimal('paid_days', 5, 2)->default(0)->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_vacation_leave')) {
                $table->decimal('balance_vacation_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_sick_leave')) {
                $table->decimal('balance_sick_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_wellness_leave')) {
                $table->decimal('balance_wellness_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_solo_parent_leave')) {
                $table->decimal('balance_solo_parent_leave', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'balance_special_leave_privilege')) {
                $table->decimal('balance_special_leave_privilege', 8, 2)->nullable()->change();
            }
            if (Schema::hasColumn('leave_requests', 'lwop_days')) {
                $table->decimal('lwop_days', 5, 2)->nullable()->change();
            }
        });
    }
};
