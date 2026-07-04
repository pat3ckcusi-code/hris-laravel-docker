<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guards keep this migration safe to run after a backup restore,
        // where these columns may already exist.
        if (! Schema::hasColumn('plantillas', 'item_number')) {
            Schema::table('plantillas', function (Blueprint $table) {
                $table->string('item_number')->nullable()->unique()->after('title');
                $table->string('department')->nullable()->after('item_number');
            });
        }
    }

    public function down(): void
    {
        Schema::table('plantillas', function (Blueprint $table) {
            $table->dropUnique(['item_number']);
            $table->dropColumn(['item_number', 'department']);
        });
    }
};
