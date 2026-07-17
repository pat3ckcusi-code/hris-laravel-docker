<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAdjustmentSubmissionItem;
use App\Models\User;
use App\Services\AttendanceAdjustmentSummaryService;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leave Manager screen that consumes the snapshots forwarded by
 * AttendanceAdjustmentSummaryController (Timekeeper/HR Manager side) and
 * actually applies the Vacation Leave deduction those submissions exist to
 * justify - or dismisses a flagged item as a false positive. This is the
 * first code path that turns a stored attendance-deficiency snapshot into a
 * leave_balances write.
 */
class AttendanceAdjustmentReviewController extends Controller
{
    private const ALLOWED_ROLES = ['leave manager'];

    public function __construct(private readonly AttendanceAdjustmentSummaryService $summaryService) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::ALLOWED_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $filters = [
            'month' => $request->integer('month') ?: null,
            'year' => $request->integer('year') ?: null,
            'department' => $request->input('department') ?: null,
            'search' => $request->input('search') ?: null,
            'issue' => $request->input('issue') ?: 'unfiled',
        ];

        $items = $this->summaryService->pendingItemsForLeaveManager($filters);
        $items->getCollection()->transform(function (AttendanceAdjustmentSubmissionItem $item) {
            $item->suggested_deduction = round($this->summaryService->computeSuggestedDeduction($item), 3);

            return $item;
        });

        $departments = AttendanceAdjustmentSubmissionItem::query()
            ->pending()
            ->whereHas('submission', fn ($q) => $q->where('status', 'submitted'))
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        return view('leave-manager.attendance-deductions', [
            'items' => $items,
            'departments' => $departments,
            'filters' => $filters,
        ]);
    }

    public function apiDeduct(Request $request, AttendanceAdjustmentSubmissionItem $item): JsonResponse
    {
        $this->authorizeManager($request->user());

        try {
            $result = $this->summaryService->deductForItem($item, $request->user());

            return response()->json([
                'success' => true,
                'message' => "Deducted {$result['deducted_days']} VL day(s).",
                'deducted_days' => $result['deducted_days'],
                'balance_after' => $result['balance_after'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function apiDismiss(Request $request, AttendanceAdjustmentSubmissionItem $item): JsonResponse
    {
        $this->authorizeManager($request->user());

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->summaryService->dismissItem($item, $validated['remarks'], $request->user());

            return response()->json(['success' => true, 'message' => 'Item dismissed.']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function apiBulkDeduct(Request $request): JsonResponse
    {
        $this->authorizeManager($request->user());

        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
        ]);

        $result = $this->summaryService->bulkDeduct($validated['item_ids'], $request->user());

        return response()->json([
            'success' => true,
            'processed_count' => count($result['processed']),
            'errors' => $result['errors'],
        ]);
    }

    public function apiBulkDismiss(Request $request): JsonResponse
    {
        $this->authorizeManager($request->user());

        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $result = $this->summaryService->bulkDismiss($validated['item_ids'], $validated['remarks'], $request->user());

        return response()->json([
            'success' => true,
            'processed_count' => count($result['processed']),
            'errors' => $result['errors'],
        ]);
    }
}
