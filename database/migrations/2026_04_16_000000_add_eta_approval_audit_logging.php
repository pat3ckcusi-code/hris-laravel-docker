<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add audit columns to eta table to track approver role normalization
        Schema::table('eta', function (Blueprint $table) {
            if (!Schema::hasColumn('eta', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('status');
                $table->string('approved_role')->nullable()->after('approved_by')->comment('Normalized role of approver (department head, administrative officer)');
                $table->timestamp('approved_at')->nullable()->after('approved_role');
                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eta', function (Blueprint $table) {
            if (Schema::hasColumn('eta', 'approved_by')) {
                $table->dropForeign(['approved_by']);
                $table->dropColumn(['approved_by', 'approved_role', 'approved_at']);
            }
        });
    }
};
