<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * esignature_requests backed the original "sign one document right now" prototype
     * flow, superseded by the persistent esignature_settings table (see the sibling
     * migration). Only ever held test data from this session - safe to drop outright.
     * App\Models\ESignatureRequest and App\Jobs\SignESignatureRequestPdfJob are left in
     * place but are now dormant as a result (see the comments on those files).
     */
    public function up(): void
    {
        Schema::dropIfExists('esignature_requests');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('esignature_requests', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('signature_path');
            $table->string('pdf_path')->nullable();
            $table->timestamp('signing_failed_at')->nullable();
            $table->timestamps();
        });
    }
};
