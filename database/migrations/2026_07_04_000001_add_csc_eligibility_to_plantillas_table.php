<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plantillas', 'csc_eligibility')) {
            Schema::table('plantillas', function (Blueprint $table) {
                $table->enum('csc_eligibility', ['professional', 'sub_professional', 'none'])
                    ->nullable()
                    ->after('employment_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('plantillas', function (Blueprint $table) {
            $table->dropColumn('csc_eligibility');
        });
    }
};
