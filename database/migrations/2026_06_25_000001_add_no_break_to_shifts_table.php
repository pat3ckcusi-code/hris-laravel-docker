<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('no_break')->default(false)->after('crosses_midnight');
            $table->time('break_out')->nullable()->change();
            $table->time('break_in')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('no_break');
            $table->time('break_out')->nullable(false)->change();
            $table->time('break_in')->nullable(false)->change();
        });
    }
};
