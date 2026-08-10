<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withholding_taxes', function (Blueprint $table) {
            $table->text('amount')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('withholding_taxes', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->default(0)->change();
        });
    }
};
