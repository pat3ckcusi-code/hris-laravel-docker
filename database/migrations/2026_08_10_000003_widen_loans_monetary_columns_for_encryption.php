<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->text('balance')->nullable()->change();
            $table->text('monthly_payment')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('balance', 12, 2)->default(0)->change();
            $table->decimal('monthly_payment', 12, 2)->default(0)->change();
        });
    }
};
