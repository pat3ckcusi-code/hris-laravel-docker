<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_inspection_details', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('uniform_inspection_details', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('offense_number');
        });
    }
};
