<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('earnings', function (Blueprint $table) {
            // pera | hazard_pay | subsistence_allowance | laundry_allowance | other
            $table->string('allowance_type')->nullable()->after('type');
        });

        Schema::table('deductions', function (Blueprint $table) {
            // mandatory | loan | other
            $table->string('deduction_category')->nullable()->after('type');
            // gsis_premium | philhealth | pagibig | bir |
            // gsis_loan | pagibig_loan | cooperative_loan | salary_loan | provident_fund | other
            $table->string('deduction_type')->nullable()->after('deduction_category');
        });
    }

    public function down(): void
    {
        Schema::table('earnings', function (Blueprint $table) {
            $table->dropColumn('allowance_type');
        });

        Schema::table('deductions', function (Blueprint $table) {
            $table->dropColumn(['deduction_category', 'deduction_type']);
        });
    }
};
