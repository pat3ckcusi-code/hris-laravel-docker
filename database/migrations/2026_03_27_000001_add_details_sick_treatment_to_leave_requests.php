<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'details_sick_treatment')) {
                if (Schema::hasColumn('leave_requests', 'details_sick_illness')) {
                    $table->string('details_sick_treatment')->nullable()->after('details_sick_illness');
                } else {
                    $table->string('details_sick_treatment')->nullable();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'details_sick_treatment')) {
                $table->dropColumn('details_sick_treatment');
            }
        });
    }
};
