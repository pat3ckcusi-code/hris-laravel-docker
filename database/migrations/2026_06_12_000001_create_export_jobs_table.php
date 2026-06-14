<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->json('params');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('result_path', 500)->nullable();
            $table->string('result_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
