<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csc_eligibility_options', function (Blueprint $table) {
            $table->id();
            // Machine value stored verbatim in plantillas.csc_eligibility. Derived
            // from `label` via Str::slug() at creation time (see
            // CscEligibilityOptionsController::store()) and immutable afterwards -
            // never edited directly, so no app-level validation rule targets it.
            $table->string('key', 150)->unique();
            $table->string('label', 150)->unique();
            $table->timestamps();
        });

        // Seed the 3 categories that exist today as
        // enum('professional', 'sub_professional', 'none') on
        // plantillas.csc_eligibility, using the exact keys/labels already in
        // use so existing Plantilla rows and PlantillaUiTest.php keep working
        // unmodified.
        //
        // IMPORTANT: these key values are inserted verbatim below, NOT
        // derived via Str::slug($label, '_') - slugifying "No Required CSC"
        // produces "no_required_csc", not "none", which would silently
        // orphan every existing Plantilla row with csc_eligibility = 'none'.
        $now = now();
        DB::table('csc_eligibility_options')->insert([
            ['key' => 'professional', 'label' => 'Professional', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'sub_professional', 'label' => 'Sub-Professional', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'none', 'label' => 'No Required CSC', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('csc_eligibility_options');
    }
};
