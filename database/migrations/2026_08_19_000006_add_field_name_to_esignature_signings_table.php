<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esignature_signings', function (Blueprint $table) {
            // Null/absent -> SignESignatureRequestPdfJob falls back to the literal
            // 'Signature' (today's behaviour, unchanged). A co-signing pass (a second
            // signature added on top of an already-signed document, e.g. an approver
            // countersigning a leave the applicant already e-signed at filing) must use
            // a distinct AcroForm field name from the first signature's, since pyHanko
            // adds a new field per addsig call - see SignESignatureRequestPdfJob's
            // buildFieldSpec().
            $table->string('field_name')->nullable()->after('signable_id');
        });
    }

    public function down(): void
    {
        Schema::table('esignature_signings', function (Blueprint $table) {
            $table->dropColumn('field_name');
        });
    }
};
