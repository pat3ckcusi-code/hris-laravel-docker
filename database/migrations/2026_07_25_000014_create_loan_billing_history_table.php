<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_billing_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->date('billing_month');
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('monthly_payment', 12, 2)->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Re-uploading the same month for the same loan updates that
            // month's snapshot (updateOrCreate) rather than duplicating it.
            $table->unique(['loan_id', 'billing_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_billing_history');
    }
};
