<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eta', function (Blueprint $table) {
            if (Schema::hasColumn('eta', 'intended_departure_time')) {
                $table->dropColumn('intended_departure_time');
            }
            if (Schema::hasColumn('eta', 'intended_arrival_time')) {
                $table->dropColumn('intended_arrival_time');
            }
            if (Schema::hasColumn('eta', 'actual_arrival_time')) {
                $table->dropColumn('actual_arrival_time');
            }

            if (! Schema::hasColumn('eta', 'arrival_date')) {
                $table->date('arrival_date')->nullable()->after('departure_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('eta', function (Blueprint $table) {
            if (Schema::hasColumn('eta', 'arrival_date')) {
                $table->dropColumn('arrival_date');
            }

            if (! Schema::hasColumn('eta', 'intended_departure_time')) {
                $table->time('intended_departure_time')->nullable()->after('departure_date');
            }
            if (! Schema::hasColumn('eta', 'intended_arrival_time')) {
                $table->time('intended_arrival_time')->nullable()->after('intended_departure_time');
            }
            if (! Schema::hasColumn('eta', 'actual_arrival_time')) {
                $table->time('actual_arrival_time')->nullable()->after('intended_arrival_time');
            }
        });
    }
};
