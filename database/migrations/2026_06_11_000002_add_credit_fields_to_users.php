<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_sanggunian_member')->default(false)->after('employee_type');
            $table->boolean('on_extended_service')->default(false)->after('is_sanggunian_member');
            $table->decimal('hours_per_day', 4, 2)->nullable()->after('on_extended_service');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_sanggunian_member', 'on_extended_service', 'hours_per_day']);
        });
    }
};
