<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class FrontDeskController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureFrontDesk($request);

        return view('front-desk', [
            'summary' => $this->buildSummary($request),
            'documentTypes' => DocumentRequest::query()
                ->select('document_type')
                ->distinct()
                ->orderBy('document_type')
                ->pluck('document_type'),
        ]);
    }

    public function fetchRequests(Request $request): JsonResponse
    {
        $this->ensureFrontDesk($request);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'month' => ['nullable', 'date_format:Y-m'],
            'document_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $requests = $this->filteredRequests($validated)
            ->orderByDesc('document_requests.requested_on')
            ->orderByDesc('document_requests.id')
            ->get()
            ->map(fn (DocumentRequest $requestItem) => $this->transformRequest($requestItem));

        return response()->json([
            'summary' => $this->buildSummaryFromCollection($requests),
            'pending' => $requests->filter(function (array $item): bool {
                return $item['status'] === 'Requested';
            })->values(),
            'approved' => $requests->filter(function (array $item): bool {
                return in_array($item['status'], ['Accepted', 'Completed'], true);
            })->values(),
        ]);
    }

    public function acceptRequest(Request $request): JsonResponse
    {
        $this->ensureFrontDesk($request);

        $validated = $request->validate([
            'request_id' => ['required', 'integer', 'exists:document_requests,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $documentRequest = DocumentRequest::query()->findOrFail($validated['request_id']);
        $actor = (string) ($request->user()->name ?: $request->user()->email ?: 'System');

        $documentRequest->status = 'Accepted';
        $documentRequest->processed_on = now();
        $documentRequest->processed_by = $actor;
        $documentRequest->hr_notes = $validated['remarks'] ?? $documentRequest->hr_notes;
        $documentRequest->save();

        $this->sendStatusEmail(
            $documentRequest,
            'Document Request Accepted',
            'Your requested document <strong>' . e((string) $documentRequest->document_type) . '</strong> has been accepted and is now being processed.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Request accepted and employee has been notified.',
        ]);
    }

    public function rejectRequest(Request $request): JsonResponse
    {
        $this->ensureFrontDesk($request);

        $validated = $request->validate([
            'request_id' => ['required', 'integer', 'exists:document_requests,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $documentRequest = DocumentRequest::query()->findOrFail($validated['request_id']);
        $actor = (string) ($request->user()->name ?: $request->user()->email ?: 'System');

        $documentRequest->status = 'Rejected';
        $documentRequest->processed_on = now();
        $documentRequest->processed_by = $actor;
        $documentRequest->hr_notes = $validated['remarks'] ?? $documentRequest->hr_notes;
        $documentRequest->save();

        $this->sendStatusEmail(
            $documentRequest,
            'Document Request Rejected',
            'Your requested document <strong>' . e((string) $documentRequest->document_type) . '</strong> has been rejected. Please contact the HR office for clarification.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Request rejected and employee has been notified.',
        ]);
    }

    public function completeRequest(Request $request): JsonResponse
    {
        $this->ensureFrontDesk($request);

        $validated = $request->validate([
            'request_id' => ['required', 'integer', 'exists:document_requests,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $documentRequest = DocumentRequest::query()->findOrFail($validated['request_id']);
        $actor = (string) ($request->user()->name ?: $request->user()->email ?: 'System');

        $documentRequest->status = 'Completed';
        $documentRequest->processed_on = $documentRequest->processed_on ?: now();
        $documentRequest->processed_by = $documentRequest->processed_by ?: $actor;
        $documentRequest->released_on = now();
        $documentRequest->released_by = $actor;
        $documentRequest->hr_notes = $validated['remarks'] ?? $documentRequest->hr_notes;
        $documentRequest->save();

        $this->sendStatusEmail(
            $documentRequest,
            'Document Request Completed',
            'Your requested document <strong>' . e((string) $documentRequest->document_type) . '</strong> has been completed and is ready for pick-up at the HR office.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Request marked as completed and employee has been notified.',
        ]);
    }

    public function printRequest(Request $request, int $id): View
    {
        $this->ensureFrontDesk($request);

        $documentRequest = $this->filteredRequests()
            ->where('document_requests.id', $id)
            ->firstOrFail();

        $employee = User::query()->where('EmpNo', $documentRequest->EmpNo)->first();

        return view('front-desk-print-request', [
            'requestItem' => $this->transformRequest($documentRequest),
            'designation' => (string) ($employee->designation ?? $documentRequest->department_name ?? 'Employee'),
        ]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $this->ensureFrontDesk($request);

        $validated = $request->validate([
            'request_id' => ['required', 'integer', 'exists:document_requests,id'],
            'status' => ['required', 'string', 'in:Requested,Accepted,Completed,Rejected'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $documentRequest = DocumentRequest::query()->findOrFail($validated['request_id']);
        $status = $validated['status'];
        $actor = (string) ($request->user()->name ?: $request->user()->email ?: 'System');

        $documentRequest->status = $status;
        $documentRequest->hr_notes = $validated['remarks'] ?? $documentRequest->hr_notes;

        if (in_array($status, ['Accepted', 'Rejected'], true)) {
            $documentRequest->processed_on = now();
            $documentRequest->processed_by = $actor;
        }

        if ($status === 'Completed') {
            $documentRequest->processed_on = $documentRequest->processed_on ?: now();
            $documentRequest->processed_by = $documentRequest->processed_by ?: $actor;
            $documentRequest->released_on = now();
            $documentRequest->released_by = $actor;
        }

        if ($status === 'Requested') {
            $documentRequest->processed_on = null;
            $documentRequest->processed_by = null;
            $documentRequest->released_on = null;
            $documentRequest->released_by = null;
        }

        $documentRequest->save();

        return response()->json([
            'success' => true,
            'message' => 'Request status updated successfully.',
        ]);
    }

    public function printReport(Request $request)
    {
        $this->ensureFrontDesk($request);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'month' => ['nullable', 'date_format:Y-m'],
            'document_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'scope' => ['nullable', 'string', 'in:all,pending,approved'],
            'request_id' => ['nullable', 'integer', 'exists:document_requests,id'],
        ]);

        $query = $this->filteredRequests($validated);

        if (!empty($validated['request_id'])) {
            $query->where('document_requests.id', $validated['request_id']);
        }

        if (($validated['scope'] ?? 'all') === 'pending') {
            $query->whereIn('document_requests.status', ['Requested', 'Pending']);
        }

        if (($validated['scope'] ?? 'all') === 'approved') {
            $query->whereIn('document_requests.status', ['Accepted', 'Completed']);
        }

        $requests = $query
            ->orderByDesc('document_requests.requested_on')
            ->orderByDesc('document_requests.id')
            ->get()
            ->map(fn (DocumentRequest $requestItem) => $this->transformRequest($requestItem));

        return response()->view('front-desk-print', [
            'requests' => $requests,
            'summary' => $this->buildSummaryFromCollection($requests),
            'filters' => [
                'date' => $validated['date'] ?? null,
                'month' => $validated['month'] ?? null,
                'document_type' => $validated['document_type'] ?? null,
                'status' => $validated['status'] ?? null,
                'scope' => $validated['scope'] ?? 'all',
            ],
        ]);
    }

    private function filteredRequests(array $filters = []): Builder
    {
        $query = DocumentRequest::query()
            ->leftJoin('users', 'document_requests.EmpNo', '=', 'users.EmpNo')
            ->leftJoin('departments', 'users.Dept_id', '=', 'departments.Dept_id')
            ->select([
                'document_requests.*',
                'users.name as employee_name',
                'departments.Dept_name as department_name',
            ]);

        if (!empty($filters['date'])) {
            $query->whereDate('document_requests.requested_on', $filters['date']);
        }

        if (!empty($filters['month'])) {
            $month = Carbon::createFromFormat('Y-m', $filters['month']);
            $query
                ->whereYear('document_requests.requested_on', $month->year)
                ->whereMonth('document_requests.requested_on', $month->month);
        }

        if (!empty($filters['document_type'])) {
            $query->where('document_requests.document_type', $filters['document_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('document_requests.status', $filters['status']);
        }

        return $query;
    }

    private function buildSummary(Request $request): array
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'month' => ['nullable', 'date_format:Y-m'],
            'document_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $requests = $this->filteredRequests($filters)->get()->map(fn (DocumentRequest $requestItem) => [
            'status' => (string) $requestItem->status,
        ]);

        return $this->buildSummaryFromCollection($requests);
    }

    private function buildSummaryFromCollection($requests): array
    {
        return [
            'total' => $requests->count(),
            'pending' => $requests->where('status', 'Requested')->count(),
            'approved' => $requests->where('status', 'Accepted')->count(),
            'completed' => $requests->where('status', 'Completed')->count(),
        ];
    }

    private function transformRequest(DocumentRequest $requestItem): array
    {
        return [
            'id' => $requestItem->id,
            'emp_no' => $requestItem->EmpNo,
            'employee_name' => $requestItem->employee_name ?: 'Unknown Employee',
            'department' => $requestItem->department_name ?: '-',
            'document_type' => $requestItem->document_type,
            'purpose' => $requestItem->purpose,
            'requested_on' => optional($requestItem->requested_on)->format('M d, Y h:i A') ?: '-',
            'status' => (string) $requestItem->status,
            'remarks' => $requestItem->hr_notes ?: '-',
        ];
    }

    private function ensureFrontDesk(Request $request): void
    {
        $role = $this->normalizeRole((string) $request->user()->access_level);
        abort_unless($role === 'front desk', 403, 'Only Front Desk users can access this section.');
    }

    private function normalizeRole(string $role): string
    {
        return strtolower(trim($role));
    }

    private function sendStatusEmail(DocumentRequest $documentRequest, string $subject, string $line): void
    {
        $employee = User::query()->where('EmpNo', $documentRequest->EmpNo)->first();

        if (! $employee || ! $employee->email) {
            return;
        }

        $employeeName = (string) ($employee->name ?: ($employee->first_name ?? 'Employee'));

        $html = '<p>Dear ' . e($employeeName) . ',</p>'
            . '<p>' . $line . '</p>'
            . '<p>We will notify you once it is ready.</p>'
            . '<br><p>City Human Resource Office Department</p>';

        Mail::html($html, function ($message) use ($employee, $subject): void {
            $message->to($employee->email)->subject($subject);
        });
    }
}
