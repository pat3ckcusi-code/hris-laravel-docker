<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\ESignatureCredentialStore;
use App\Services\LeaveRequestService;
use App\Support\RoleNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * HR Manager/Leave Manager screen for the "Certification of Leave Credits" line on
 * e-signature-filed leave PDFs, split into two separate transactions:
 *
 * - The Leave Manager reviews the pending queue (index()'s "Pending Review" list,
 *   LeaveRequestService::pendingReviewQuery()) and either reject()s a leave with a
 *   required reason, or forward()s it (bulk-selectable, no reason) so it's ready to
 *   sign.
 * - The HR Manager signs only forwarded()d leaves (batchSign(),
 *   LeaveRequestService::forwardedForSigningQuery()), always with their own logged-in
 *   account's saved PNPKI certificate - never anyone else's, unlike the previous
 *   design this replaced.
 *
 * A rejected leave lands on the Rejected tab rather than disappearing; either role
 * can reopen() it back to pending review once the underlying issue is resolved.
 * Self-guarded (via User::canAccessLeaveCertification() for page access, plus the
 * role-specific ensureLeaveManagerAccess()/ensureHrManagerAccess() below for the
 * review/sign actions themselves) rather than route-level role: middleware, since
 * it's genuinely shared by two role groups that don't otherwise share a route group -
 * mirrors Records\JobOrderAppointmentController's own self-guard convention.
 */
class LeaveCertificationController extends Controller
{
    public function __construct(private readonly LeaveRequestService $leaveRequestService) {}

    public function index(Request $request): View
    {
        $this->ensureAccess($request);

        $filters = [
            'department' => $request->input('department') ?: null,
            'search' => $request->input('search') ?: null,
        ];

        $isHrManager = $this->normalizedRole($request) === 'hr manager';

        $pending = $isHrManager ? null : $this->leaveRequestService->paginatedPendingCertificationLeaves($filters);
        $forwarded = $this->leaveRequestService->paginatedForwardedForSigning($filters);
        $rejected = $this->leaveRequestService->paginatedRejectedCertifications($filters);
        $history = $this->leaveRequestService->paginatedCertificationHistory($filters);
        $departments = $this->leaveRequestService->certificationFilterDepartments();

        return view('leave-certification.index', [
            'isHrManager' => $isHrManager,
            'isLeaveManager' => ! $isHrManager,
            'pending' => $pending,
            'forwarded' => $forwarded,
            'rejected' => $rejected,
            'history' => $history,
            'departments' => $departments,
            'filters' => $filters,
        ]);
    }

    public function batchSign(Request $request, ESignatureCredentialStore $credentialStore): JsonResponse
    {
        $this->ensureAccess($request);
        $this->ensureHrManagerAccess($request);

        $validated = $request->validate([
            'pnpki_password' => ['required', 'string'],
            'leave_ids' => ['nullable', 'array'],
            'leave_ids.*' => ['integer'],
        ]);

        try {
            $result = $this->leaveRequestService->batchCertifyPendingLeaves(
                $request->user(),
                $validated['pnpki_password'],
                $credentialStore,
                $validated['leave_ids'] ?? null
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $count = count($result['processed']);
        $errorCount = count($result['errors']);

        return response()->json([
            'success' => true,
            'processed_count' => $count,
            'errors' => $result['errors'],
            'message' => $count > 0
                ? "Queued {$count} leave(s) for signing.".($errorCount ? " {$errorCount} failed." : '')
                : 'Nothing to sign - the queue is empty.',
        ]);
    }

    public function forward(Request $request): JsonResponse
    {
        $this->ensureAccess($request);
        $this->ensureLeaveManagerAccess($request);

        $validated = $request->validate([
            'leave_ids' => ['nullable', 'array'],
            'leave_ids.*' => ['integer'],
        ]);

        $result = $this->leaveRequestService->forwardCertifications(
            $request->user(),
            $validated['leave_ids'] ?? null
        );

        $count = count($result['processed']);

        return response()->json([
            'success' => true,
            'processed_count' => $count,
            'errors' => $result['errors'],
            'message' => $count > 0
                ? "Forwarded {$count} leave(s) to the HR Manager for signing."
                : 'Nothing to forward - the queue is empty.',
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request);
        $this->ensureLeaveManagerAccess($request);

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ]);

        $leave = LeaveRequest::findOrFail($id);

        try {
            $this->leaveRequestService->rejectCertification($leave, $request->user(), $validated['remarks']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Leave certification rejected.']);
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request);

        $leave = LeaveRequest::findOrFail($id);

        try {
            $this->leaveRequestService->reopenCertification($leave, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Leave sent back to pending review.']);
    }

    private function normalizedRole(Request $request): string
    {
        return RoleNormalizer::normalize((string) ($request->user()?->access_level ?? ''));
    }

    private function ensureAccess(Request $request): void
    {
        abort_unless($request->user()?->canAccessLeaveCertification(), 403);
    }

    private function ensureLeaveManagerAccess(Request $request): void
    {
        abort_unless($this->normalizedRole($request) === 'leave manager', 403);
    }

    private function ensureHrManagerAccess(Request $request): void
    {
        abort_unless($this->normalizedRole($request) === 'hr manager', 403);
    }
}
