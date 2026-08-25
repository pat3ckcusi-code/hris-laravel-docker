<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Implemented by any Eloquent model that can be rendered to PDF and signed
 * with an employee's saved PNPKI e-signature via SignESignatureRequestPdfJob.
 * Keeps that job and its EsignatureSigning tracking row generic across
 * document types - a new signable only needs these two methods, no changes
 * to the job, the migration, or the controller that kicks off signing.
 */
interface Signable
{
    /**
     * The user whose ESignatureSetting (certificate, signature image, trust
     * chain) should be used to sign this document.
     */
    public function esignatureOwner(): User;

    /**
     * Where the finished signed PDF should be served from once signing
     * completes - what the polling UI's download link points at.
     */
    public function esignaturePrintUrl(): string;
}
