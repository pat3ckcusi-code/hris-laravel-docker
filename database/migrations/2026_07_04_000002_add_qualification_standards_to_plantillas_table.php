<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plantillas', 'education')) {
            Schema::table('plantillas', function (Blueprint $table) {
                $table->text('education')->nullable()->after('csc_eligibility');
                $table->text('training')->nullable()->after('education');
                $table->text('experience')->nullable()->after('training');
                $table->text('competency')->nullable()->after('experience');
            });
        }
    }

    public function down(): void
    {
        Schema::table('plantillas', function (Blueprint $table) {
            $table->dropColumn(['education', 'training', 'experience', 'competency']);
        });
    }
};
