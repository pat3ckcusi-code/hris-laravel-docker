<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deductions', function (Blueprint $table) {
            // Which HrisConstants::EMPLOYEE_TYPES a mandatory row (gsis/philhealth/
            // pagibig/bir) applies to. Null = no restriction, applies to every type
            // (today's behavior, unchanged) - see PayrollComputationService::
            // mandatoryAppliesToEmployee(). Only meaningful for mandatory_key rows;
            // never read for Loan/Other categories.
            $table->json('eligible_employee_types')->nullable()->after('mandatory_config');
        });
    }

    public function down(): void
    {
        Schema::table('deductions', function (Blueprint $table) {
            $table->dropColumn('eligible_employee_types');
        });
    }
};
