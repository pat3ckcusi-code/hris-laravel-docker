<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'printing_deduction_applied')) {
                $table->boolean('printing_deduction_applied')->default(false)->after('printing_allowed');
            }
            if (!Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
                $table->text('printing_deduction_details')->nullable()->after('printing_deduction_applied');
            }
        });
    }

    public function down()
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
                $table->dropColumn('printing_deduction_details');
            }
            if (Schema::hasColumn('leave_requests', 'printing_deduction_applied')) {
                $table->dropColumn('printing_deduction_applied');
            }
        });
    }
};
