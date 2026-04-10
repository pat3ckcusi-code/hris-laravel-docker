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
        Schema::create('hr_audit_trails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('module', 60);
            $table->string('action', 60);
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['module', 'action']);
            $table->index('created_at');
            $table->index(['target_type', 'target_id']);

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_audit_trails', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
        });

        Schema::dropIfExists('hr_audit_trails');
    }
};
