<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_inspections', function (Blueprint $table) {
            $table->dropForeign(['inspector_id']);
            $table->dropColumn('inspector_id');
        });
    }

    public function down(): void
    {
        Schema::table('uniform_inspections', function (Blueprint $table) {
            $table->foreignId('inspector_id')->after('id')->constrained('users')->cascadeOnDelete();
        });
    }
};
