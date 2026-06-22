<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_earnings', function (Blueprint $table) {
            $table->string('amount_type')->default('fixed')->after('amount');
            $table->decimal('percentage', 5, 2)->nullable()->after('amount_type');
        });
    }

    public function down(): void
    {
        Schema::table('employee_earnings', function (Blueprint $table) {
            $table->dropColumn(['amount_type', 'percentage']);
        });
    }
};
