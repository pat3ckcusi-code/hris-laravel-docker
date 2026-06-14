<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('auto_import_enabled')->default(false);
            $table->unsignedSmallInteger('auto_import_interval_minutes')->default(30);
            $table->unsignedInteger('auto_import_dept_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['auto_import_enabled', 'auto_import_interval_minutes', 'auto_import_dept_id']);
        });
    }
};
