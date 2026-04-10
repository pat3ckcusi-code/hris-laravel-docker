<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds snapshot columns for leave balances to support auditing/printing.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // store employee's leave balances at time of filing
            if (!Schema::hasColumn('leave_requests', 'balance_vacation_leave')) {
                $table->decimal('balance_vacation_leave', 8, 2)->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'balance_sick_leave')) {
                $table->decimal('balance_sick_leave', 8, 2)->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'balance_wellness_leave')) {
                $table->decimal('balance_wellness_leave', 8, 2)->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'balance_solo_parent_leave')) {
                $table->decimal('balance_solo_parent_leave', 8, 2)->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'balance_special_leave_privilege')) {
                $table->decimal('balance_special_leave_privilege', 8, 2)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn([
                'balance_vacation_leave',
                'balance_sick_leave',
                'balance_wellness_leave',
                'balance_solo_parent_leave',
                'balance_special_leave_privilege',
            ]);
        });
    }
};
