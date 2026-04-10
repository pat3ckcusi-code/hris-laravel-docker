<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentRequestController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $requests = DocumentRequest::query()
            ->where('EmpNo', $user->EmpNo)
            ->orderByDesc('requested_on')
            ->orderByDesc('id')
            ->paginate(15);

        return view('request_documents', [
            'user' => $user,
            'requests' => $requests,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:100'],
            'purpose' => ['required', 'string', 'max:1000'],
        ]);

        $user = Auth::user();

        DocumentRequest::create([
            'EmpNo' => $user->EmpNo,
            'document_type' => $validated['document_type'],
            'purpose' => $validated['purpose'],
            'status' => 'Requested',
            'requested_on' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Document request submitted successfully.',
        ]);
    }
}
