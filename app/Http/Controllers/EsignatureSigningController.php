<?php

namespace App\Http\Controllers;

use App\Models\EsignatureSigning;
use Illuminate\Http\JsonResponse;

/**
 * Generic polling endpoint for any EsignatureSigning attempt, regardless of
 * which Signable document it belongs to - kept deliberately thin/document-
 * agnostic, mirroring ExportJobController::status()'s shape, so a future
 * signable (Travel Order, Office Order) needs no new controller here.
 */
class EsignatureSigningController extends Controller
{
    public function status(EsignatureSigning $signing): JsonResponse
    {
        abort_unless($signing->requested_by === auth()->id(), 403);

        $data = ['status' => $signing->status];

        if ($signing->status === EsignatureSigning::STATUS_COMPLETED) {
            $data['download_url'] = $signing->signable?->esignaturePrintUrl();
        }

        if ($signing->status === EsignatureSigning::STATUS_FAILED) {
            $data['error'] = $signing->error_message;
        }

        return response()->json($data);
    }
}
