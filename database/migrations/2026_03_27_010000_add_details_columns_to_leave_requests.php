<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('details_location')->nullable()->after('reason');
            $table->text('details_location_specify')->nullable()->after('details_location');
            $table->text('details_sick_illness')->nullable()->after('details_location_specify');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn([
                'details_location', 'details_location_specify', 'details_sick_illness'
            ]);
        });
    }
};
