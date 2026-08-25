<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dormant since the esignature_requests table was dropped (see migration
 * 2026_08_19_000003_drop_esignature_requests_table.php) when E-Signature Config
 * was rebuilt as a persistent per-employee setting (App\Models\ESignatureSetting)
 * instead of this model's original "sign one document right now" design. Using
 * this model will throw "Base table or view not found" - that's expected, not a
 * bug. App\Jobs\SignESignatureRequestPdfJob no longer references this class -
 * it was reworked to sign any App\Contracts\Signable document (tracked via
 * App\Models\EsignatureSigning) instead, resolving cert/chain/signature
 * material from ESignatureSetting. This model is kept only because
 * pnpki:verify (App\Console\Commands\VerifyPnpkiSignature) still looks
 * signing attempts up by an ESignatureRequest ID - that command is likewise
 * broken today (same dropped-table error) and would need the same kind of
 * rework, pointed at EsignatureSigning instead, before it's usable again.
 */
class ESignatureRequest extends Model
{
    protected $table = 'esignature_requests';

    protected $fillable = [
        'name',
        'signature_path',
        'pdf_path',
        'signing_failed_at',
    ];

    protected $casts = [
        'signing_failed_at' => 'datetime',
    ];

    public function isSigned(): bool
    {
        return ! is_null($this->pdf_path);
    }

    public function hasFailed(): bool
    {
        return ! is_null($this->signing_failed_at);
    }
}
