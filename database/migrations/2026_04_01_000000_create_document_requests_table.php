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
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->string('EmpNo');
            $table->string('document_type');
            $table->text('purpose');
            $table->string('status')->default('Requested');
            $table->dateTime('requested_on');
            $table->dateTime('processed_on')->nullable();
            $table->dateTime('released_on')->nullable();
            $table->string('processed_by')->nullable();
            $table->string('released_by')->nullable();
            $table->text('hr_notes')->nullable();
            $table->timestamps();

            $table->index(['EmpNo', 'status']);
            $table->index('requested_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
