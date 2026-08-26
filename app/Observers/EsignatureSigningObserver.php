<?php

namespace App\Observers;

use App\Models\DocumentRequest;
use App\Models\EsignatureSigning;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Support\RoleNormalizer;

/**
 * Populates DocumentRequest.signed_by/signed_at once its base (field_name
 * IS NULL) signing completes - the one deliberate persisted exception to
 * this codebase's usual "derive is-it-signed from EsignatureSigning at read
 * time" convention, added specifically to give document requests a real
 * signer FK instead of the free-text processed_by/released_by pattern.
 * Scoped away from LeaveRequest's own base signing (and any co-signing
 * pass, via the field_name check) so this never touches anything but a
 * document request's own sole signature.
 */
class EsignatureSigningObserver
{
    public function updated(EsignatureSigning $signing): void
    {
        if (! $signing->wasChanged('status') || $signing->status !== EsignatureSigning::STATUS_COMPLETED) {
            return;
        }

        if ($signing->field_name !== null) {
            return;
        }

        $signable = $signing->signable;

        if (! $signable instanceof DocumentRequest) {
            return;
        }

        $signable->update([
            'signed_by' => $signing->requested_by,
            'signed_at' => $signing->completed_at,
        ]);

        $frontDeskUsers = User::whereRaw(RoleNormalizer::rawExpression()." = 'front desk'")->get();

        foreach ($frontDeskUsers as $frontDeskUser) {
            try {
                $frontDeskUser->notify(new HrisTransactionNotification(
                    requestType: 'Document Request Signature',
                    status: 'Signed',
                    details: [
                        'Document Type' => $signable->document_type ?? 'N/A',
                        'Employee' => $signable->employee?->name ?? $signable->EmpNo,
                    ],
                    notes: 'Signed and ready to print and complete.',
                ));
            } catch (\Exception $ex) {
                // do not block on mail failure
            }
        }
    }
}
