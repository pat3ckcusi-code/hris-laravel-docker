<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * City Budget Office signatory ("CERTIFIED as to existence of
     * Appropriation / Obligation") for the Job Order Roster export -
     * follows the same name+designation pair pattern already used for
     * mayor_name/hr_manager_name etc.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('budget_officer_name')->nullable()->after('hr_manager_designation');
            $table->string('budget_officer_designation')->nullable()->after('budget_officer_name');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['budget_officer_name', 'budget_officer_designation']);
        });
    }
};
