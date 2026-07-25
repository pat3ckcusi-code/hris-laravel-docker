<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantillas', function (Blueprint $table) {
            if (! Schema::hasColumn('plantillas', 'is_abolished')) {
                $table->boolean('is_abolished')->default(false)->after('is_historical');
            }
            if (! Schema::hasColumn('plantillas', 'abolished_at')) {
                $table->timestamp('abolished_at')->nullable()->after('is_abolished');
            }
            if (! Schema::hasColumn('plantillas', 'abolished_by')) {
                $table->foreignId('abolished_by')->nullable()->after('abolished_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('plantillas', 'abolished_reason')) {
                $table->text('abolished_reason')->nullable()->after('abolished_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plantillas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('abolished_by');
            $table->dropColumn(['abolished_reason', 'abolished_at', 'is_abolished']);
        });
    }
};
