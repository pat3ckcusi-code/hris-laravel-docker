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
        Schema::create('esignature_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('signature_path');
            $table->string('pdf_path')->nullable();
            // Null until the signing job permanently fails (all queue retries
            // exhausted) - lets the status page distinguish "still processing"
            // from "failed" instead of an endless "check back later" that
            // never resolves.
            $table->timestamp('signing_failed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esignature_requests');
    }
};
