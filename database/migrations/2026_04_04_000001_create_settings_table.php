<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // Module toggles
            $table->boolean('records_enabled')->default(true);
            $table->boolean('leave_enabled')->default(true);
            $table->boolean('frontdesk_enabled')->default(true);
            $table->unsignedInteger('pending_alert_threshold')->default(50);
            // Email template
            $table->string('email_template_subject')->default('HRIS Notification');
            $table->text('email_template_body')->default('This is an automated HRIS update for your request.');
            // Signatories
            $table->string('mayor_name')->nullable();
            $table->string('mayor_designation')->nullable();
            $table->string('vice_mayor_name')->nullable();
            $table->string('vice_mayor_designation')->nullable();
            $table->string('hr_manager_name')->nullable();
            $table->string('hr_manager_designation')->nullable();
            $table->timestamps();
        });

        // Seed the single settings row
        DB::table('settings')->insert([
            'records_enabled'         => true,
            'leave_enabled'           => true,
            'frontdesk_enabled'       => true,
            'pending_alert_threshold' => 50,
            'email_template_subject'  => 'HRIS Notification',
            'email_template_body'     => 'This is an automated HRIS update for your request.',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
