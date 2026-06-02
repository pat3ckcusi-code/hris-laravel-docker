<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DocumentRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $documentTypes = DocumentType::all();

        $query = DocumentRequest::query()->where('EmpNo', $user->EmpNo);

        $month = $request->query('month');
        if ($month === null) {
            $month = now()->month;
        }
        if (is_numeric($month) && $month >= 1 && $month <= 12) {
            $query->whereMonth('requested_on', $month)->whereYear('requested_on', now()->year);
        }

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('document_type', 'like', '%' . $search . '%')
                  ->orWhere('purpose', 'like', '%' . $search . '%')
                  ->orWhere('status', 'like', '%' . $search . '%');
            });
        }

        $allowedSorts = ['requested_on', 'document_type', 'status', 'id'];
        $sort = $request->query('sort');
        $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderByDesc('requested_on')->orderByDesc('id');
        }

        $requests = $query->paginate(10)->withQueryString();

        return view('request_documents', [
            'user' => $user,
            'requests' => $requests,
            'documentTypes' => $documentTypes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type_id' => ['nullable', 'exists:document_types,id'],
            'document_type'    => ['nullable', 'string', 'max:100'],
            'purpose'          => ['required', 'string', 'max:1000'],
        ]);

        $user = Auth::user();
        $documentTypeName = $validated['document_type'] ?? null;

        if (!empty($validated['document_type_id'])) {
            $documentType = DocumentType::findOrFail($validated['document_type_id']);
            $documentTypeName = $documentType->name;
        }

        if (empty($documentTypeName)) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a document type.',
            ], 422);
        }

        DocumentRequest::create([
            'EmpNo'         => $user->EmpNo,
            'document_type' => $documentTypeName,
            'purpose'       => $validated['purpose'],
            'status'        => 'Requested',
            'requested_on'  => now(),
        ]);

        \Illuminate\Support\Facades\Log::info('Document request submitted', [
            'emp_no'        => $user->EmpNo,
            'document_type' => $documentTypeName,
            'status'        => 'Requested',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Document request submitted successfully.',
        ]);
    }

    public function preview(DocumentRequest $documentRequest): View
    {
        $this->authorizeEmployee($documentRequest);

        $documentType = DocumentType::where('name', $documentRequest->document_type)->first();
        $user = User::where('EmpNo', $documentRequest->EmpNo)->firstOrFail();

        $documentContent = $documentType
            ? $this->generateDocumentContent($documentType, $user)
            : [];

        return view('employee.document-preview', [
            'documentRequest' => $documentRequest,
            'documentType' => $documentType,
            'documentContent' => $documentContent,
            'user' => $user,
        ]);
    }

    public function print(DocumentRequest $documentRequest)
    {
        $this->authorizeEmployee($documentRequest);

        $documentType = DocumentType::where('name', $documentRequest->document_type)->first();
        $user = User::where('EmpNo', $documentRequest->EmpNo)->firstOrFail();

        $documentContent = $documentType
            ? $this->generateDocumentContent($documentType, $user)
            : [];

        return view('employee.document-print', [
            'documentRequest' => $documentRequest,
            'documentType' => $documentType,
            'documentContent' => $documentContent,
            'user' => $user,
        ]);
    }

    private function generateDocumentContent(DocumentType $documentType, User $user): array
    {
        $parts = $documentType->parts ?? [];

        return [
            'title'         => $parts['title'] ?? $documentType->name,
            'date'          => now()->format('F d, Y'),
            'salutation'    => $parts['salutation'] ?? 'To Whom It May Concern:',
            'employee_name' => $user->name ?? '',
            'designation'   => $user->designation ?? '',
            'employee_type' => $user->employee_type ?? '',
        ];
    }

    private function authorizeEmployee(DocumentRequest $documentRequest): void
    {
        abort_unless(
            $documentRequest->EmpNo === Auth::user()->EmpNo,
            403,
            'You are not authorized to view this document request.'
        );
    }
}
