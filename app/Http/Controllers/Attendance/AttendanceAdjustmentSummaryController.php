<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAdjustmentSubmission;
use App\Models\AttendanceAdjustmentSubmissionItem;
use App\Models\Department;
use App\Models\User;
use App\Services\AttendanceAdjustmentSummaryService;
use App\Support\RoleNormalizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Timekeeper/HR Manager screen that reviews attendance deficiencies (unfiled
 * leave, tardiness, undertime) already classified by
 * AttendanceMonitoringExportService::getRows() and forwards a filtered
 * snapshot to the Leave Manager as the future basis for Vacation Leave
 * deduction. This controller performs no classification of its own and no
 * deduction - it only presents and submits what getRows() already computed.
 */
class AttendanceAdjustmentSummaryController extends Controller
{
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    /** DataTables column order, index-matched to the client-side column definitions. */
    private const SORT_COLUMNS = [
        'emp_no', 'name', 'department', 'position', 'employee_type',
        'unfiled_count', 'tardiness_count', 'tardiness_minutes',
        'undertime_count', 'undertime_minutes', 'status',
    ];

    public function __construct(private readonly AttendanceAdjustmentSummaryService $summaryService) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $departments = Department::orderBy('Dept_name')->get();
        [$month, $year] = $this->resolveMonthYear($request);

        return view('attendance.adjustment-summary.index', compact('departments', 'month', 'year'));
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorizeManager($request->user());

        [$month, $year] = $this->resolveMonthYear($request);
        $departments = $this->resolveDepartments($request);
        $employeeType = $request->input('employee_type') ?: null;
        $issue = $request->input('issue') ?: 'unfiled';
        $search = $request->input('search.value', '');
        $minCount = $this->resolveMinCount($request);

        $rows = $this->summaryService->getFilteredRows($departments, $month, $year, $employeeType, $issue, $search, $minCount);
        $summary = $this->summaryService->buildSummaryCounts($rows);

        $start = max(0, $request->integer('start', 0));
        $length = min(100, max(1, $request->integer('length', 10)));
        $orderColumnIndex = $request->integer('order.0.column', -1);
        $orderColumn = self::SORT_COLUMNS[$orderColumnIndex] ?? null;
        $orderDir = $request->input('order.0.dir', 'asc');

        $page = $this->summaryService->paginateForDataTable($rows, $start, $length, $orderColumn, $orderDir);

        return response()->json([
            'draw' => $request->integer('draw'),
            'recordsTotal' => $rows->count(),
            'recordsFiltered' => $page['recordsFiltered'],
            'data' => $page['data']->values(),
            'summary' => $summary,
        ]);
    }

    public function submit(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeManager($request->user());

        [$month, $year] = $this->resolveMonthYear($request);
        $departments = $this->resolveDepartments($request);
        $employeeType = $request->input('employee_type') ?: null;
        $issue = $request->input('issue') ?: 'unfiled';
        $search = $request->input('search') ?: null;
        $minCount = $this->resolveMinCount($request);

        $rows = $this->summaryService->getFilteredRows($departments, $month, $year, $employeeType, $issue, $search, $minCount);
        $rows = $rows->filter(fn (array $r) => $r['unfiled_count'] > 0 || $r['tardiness_count'] > 0 || $r['undertime_count'] > 0)->values();

        $departmentIds = $departments->pluck('Dept_id')->map(fn ($id) => (int) $id)->all();
        $departmentLabel = $departments->count() > 3
            ? 'All Departments'
            : $departments->pluck('Dept_name')->filter()->implode(' / ');

        $result = $this->summaryService->submitToLeaveManager(
            $rows, $month, $year, $employeeType, $departmentIds, $departmentLabel, $request->user()
        );

        $message = "{$result['submitted_count']} employee(s) submitted to the Leave Manager.";
        if ($result['skipped_count'] > 0) {
            $message .= " {$result['skipped_count']} already-submitted employee(s) for this month were skipped.";
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'submitted_count' => $result['submitted_count'],
                'skipped_count' => $result['skipped_count'],
            ]);
        }

        return redirect()->route('attendance.adjustment-summary.index')->with('status', $message);
    }

    public function print(Request $request): View
    {
        $this->authorizeManager($request->user());

        [$month, $year] = $this->resolveMonthYear($request);
        $departments = $this->resolveDepartments($request);
        $employeeType = $request->input('employee_type') ?: null;
        $issue = $request->input('issue') ?: 'unfiled';
        $search = $request->input('search') ?: null;
        $minCount = $this->resolveMinCount($request);

        $rows = $this->summaryService->buildExportRows($departments, $month, $year, $employeeType, $issue, $search, $minCount);
        $departmentLabel = $departments->count() > 3
            ? 'All Departments'
            : $departments->pluck('Dept_name')->filter()->implode(' / ');

        return view('attendance.adjustment-summary.print', compact('rows', 'month', 'year', 'departmentLabel'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeManager($request->user());

        [$month, $year] = $this->resolveMonthYear($request);
        $departments = $this->resolveDepartments($request);
        $employeeType = $request->input('employee_type') ?: null;
        $issue = $request->input('issue') ?: 'unfiled';
        $search = $request->input('search') ?: null;
        $minCount = $this->resolveMinCount($request);

        $rows = $this->summaryService->buildExportRows($departments, $month, $year, $employeeType, $issue, $search, $minCount);
        $departmentLabel = $departments->count() > 3
            ? 'All Departments'
            : $departments->pluck('Dept_name')->filter()->implode(' / ');

        $filename = 'Attendance-Adjustment-Summary-'.$year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'.pdf';

        return Pdf::loadView('attendance.adjustment-summary.pdf', compact('rows', 'month', 'year', 'departmentLabel'))
            ->setPaper('folio', 'landscape')
            ->download($filename);
    }

    public function submissions(Request $request): View
    {
        $this->authorizeManager($request->user());

        $submissions = AttendanceAdjustmentSubmission::with('submittedBy')
            ->withCount([
                'items as pending_count' => fn ($q) => $q->where('processed_status', 'pending'),
                'items as processed_count' => fn ($q) => $q->where('processed_status', 'processed'),
                'items as dismissed_count' => fn ($q) => $q->where('processed_status', 'dismissed'),
            ])
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('attendance.adjustment-summary.submissions', compact('submissions'));
    }

    /**
     * Per-item Leave Manager review outcome for one submission - lets the
     * Timekeeper/HR Manager see which employees were dismissed (and why) so
     * they can re-review/adjust, without duplicating the Leave Manager's own
     * pending-items screen (AttendanceAdjustmentReviewController).
     */
    public function submissionItems(Request $request, AttendanceAdjustmentSubmission $submission): JsonResponse
    {
        $this->authorizeManager($request->user());

        $items = $submission->items()
            ->with('processedBy:id,name,first_name,last_name')
            ->orderBy('name')
            ->get([
                'id', 'name', 'emp_no', 'department', 'unfiled_count',
                'tardiness_minutes', 'undertime_minutes', 'processed_status',
                'deducted_days', 'action_remarks', 'processed_by', 'processed_at',
            ])
            ->map(fn (AttendanceAdjustmentSubmissionItem $item) => [
                'name' => $item->name,
                'emp_no' => $item->emp_no,
                'department' => $item->department,
                'unfiled_count' => $item->unfiled_count,
                'tardiness_minutes' => $item->tardiness_minutes,
                'undertime_minutes' => $item->undertime_minutes,
                'processed_status' => $item->processed_status,
                'deducted_days' => $item->deducted_days,
                'action_remarks' => $item->action_remarks,
                'processed_by' => $item->processedBy
                    ? (trim(($item->processedBy->first_name ?? '').' '.($item->processedBy->last_name ?? '')) ?: $item->processedBy->name)
                    : null,
                'processed_at' => $item->processed_at?->format('M d, Y g:i A'),
            ]);

        return response()->json(['items' => $items]);
    }

    private function resolveMonthYear(Request $request): array
    {
        $month = (int) $request->input('month', (int) now()->month);
        $year = (int) $request->input('year', (int) now()->year);

        if ($month < 1 || $month > 12) {
            $month = (int) now()->month;
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) now()->year;
        }

        return [$month, $year];
    }

    private function resolveMinCount(Request $request): ?int
    {
        $value = $request->input('min_count');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function resolveDepartments(Request $request): Collection
    {
        $departmentId = $request->integer('department_id') ?: null;

        if ($departmentId) {
            return Department::where('Dept_id', $departmentId)->get();
        }

        return Department::orderBy('Dept_name')->get();
    }
}
