<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('eta', 'purpose_details')) {
            Schema::table('eta', function (Blueprint $table) {
                $table->text('purpose_details')->nullable()->after('purpose');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('eta', 'purpose_details')) {
            Schema::table('eta', function (Blueprint $table) {
                $table->dropColumn('purpose_details');
            });
        }
    }
};
