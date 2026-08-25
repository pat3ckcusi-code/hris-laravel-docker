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
        Schema::create('esignature_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('signature_path');
            // The certificate is the only genuinely sensitive file here (it contains a
            // private key) - its content is encrypted at rest before being written to
            // this path, via ESignatureCredentialStore. Root CA / intermediate CA certs
            // are public certificates by definition, so they're stored plain.
            $table->string('certificate_path');
            $table->string('root_ca_path');
            $table->json('intermediate_paths');
            $table->boolean('include_name')->default(true);
            $table->boolean('include_date')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esignature_settings');
    }
};
