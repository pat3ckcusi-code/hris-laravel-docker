<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantillas', function (Blueprint $table) {
            // Convert enum to a portable string column so Payroll Managers can
            // add new CSC Eligibility categories via the settings screen (see
            // csc_eligibility_options table) without a migration. Value is a
            // loose reference to csc_eligibility_options.key - no DB-level FK
            // by design (keeps Plantilla fully decoupled; see
            // CscEligibilityOption::plantillas()). Must re-declare ->nullable()
            // here - ->change() replaces the full column definition, it doesn't
            // merge with the original.
            $table->string('csc_eligibility', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Any csc_eligibility value that isn't one of the 3 original enum
        // members can't be represented once this column shrinks back to
        // enum() - there is no lossless way to fit an admin-added category
        // into the old fixed set. Defensively null those out first so the
        // ALTER TABLE below doesn't fail outright. This is a destructive
        // fallback, not data preservation: rolling back after new categories
        // were added and used requires manual reconciliation afterwards -
        // this only guarantees the migration itself completes.
        DB::table('plantillas')
            ->whereNotNull('csc_eligibility')
            ->whereNotIn('csc_eligibility', ['professional', 'sub_professional', 'none'])
            ->update(['csc_eligibility' => null]);

        Schema::table('plantillas', function (Blueprint $table) {
            $table->enum('csc_eligibility', ['professional', 'sub_professional', 'none'])
                ->nullable()
                ->change();
        });
    }
};
