<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Time Keeper company-wide shift oversight screen: a full chronological
 * log of every shift-related change - template edits, assignments,
 * exemption toggles, schedule/rotation changes, and access grants/revokes.
 * Not extended to Department Head/Administrative Officer - they already
 * have their own dept-scoped Monitoring Matrix for tardiness/undertime.
 */
class ShiftLogController extends Controller
{
    private const MANAGER_ROLES = ['time keeper'];

    private const ACTION_LABELS = [
        'access_granted' => 'Access Granted',
        'access_revoked' => 'Access Revoked',
        'shift_assigned' => 'Shift Assigned',
        'shift_assignment_corrected' => 'Shift Corrected',
        'shift_removed' => 'Shift Removed',
        'dtr_exemption_toggled' => 'DTR Exemption',
        'shift_schedule_updated' => 'Schedule Updated',
        'rotation_generated' => 'Rotation Generated',
        'shift_template_created' => 'Template Created',
        'shift_template_updated' => 'Template Updated',
        'shift_template_deleted' => 'Template Deleted',
    ];

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $deptId = $request->integer('dept_id') ?: null;

        $departments = Department::orderBy('Dept_name')->get();
        $logs = $this->buildLogPage(query: fn ($q) => $this->scopeByDepartment($q, $deptId));

        return view('attendance.shift-logs.index', compact('departments', 'deptId', 'logs'));
    }

    /**
     * Narrow the log to entries relevant to one department: employee-targeted
     * actions for that department's staff, access grants/revokes for that
     * department itself, and shift template changes (global, always shown).
     */
    private function scopeByDepartment($query, ?int $deptId)
    {
        if ($deptId === null) {
            return $query;
        }

        $deptUserIds = User::where('Dept_id', $deptId)->pluck('id');

        return $query->where(function ($w) use ($deptId, $deptUserIds) {
            $w->where(fn ($w2) => $w2->where('target_type', 'user')->whereIn('target_id', $deptUserIds))
                ->orWhere(fn ($w2) => $w2->where('target_type', 'department')->where('target_id', $deptId))
                ->orWhere('target_type', 'shift');
        });
    }

    /**
     * A bulk action (e.g. a single-day override applied to 1,000+ employees)
     * writes one hr_audit_trails row per employee, sharing one batch_id
     * (see ShiftScheduleController::logBulkScheduleAction() and
     * EmployeeScheduleController's logBulkShiftAssigned()/logBulkShiftRemoved()).
     * Grouping by COALESCE(batch_id, id) collapses those rows into a single
     * "activity" per batch while leaving every non-batch row (batch_id is
     * null - either a genuinely single-employee action, or any row written
     * before this feature existed) grouped alone, i.e. displayed exactly as
     * before. ANY_VALUE() is safe here specifically because every row
     * sharing a real batch_id was written with an identical action/
     * target_type/actor_user_id/details in the same call - it's not a lossy
     * aggregate in practice, just how MySQL lets a functionally-determined
     * column through ONLY_FULL_GROUP_BY.
     */
    private function buildLogPage(?\Closure $query = null, string $pageName = 'log_page')
    {
        $logs = HRAuditTrail::where('module', 'shift_management')
            ->when($query, fn ($q) => $query($q))
            ->selectRaw('
                MIN(id) as id,
                batch_id,
                ANY_VALUE(action) as action,
                ANY_VALUE(target_type) as target_type,
                MIN(target_id) as target_id,
                ANY_VALUE(actor_user_id) as actor_user_id,
                ANY_VALUE(details) as details,
                MAX(created_at) as created_at,
                COUNT(*) as affected_count
            ')
            ->groupBy(DB::raw('COALESCE(batch_id, id)'), 'batch_id')
            ->with('actor:id,first_name,last_name,name')
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], $pageName)
            ->withQueryString();

        $userIds = $logs->getCollection()->where('target_type', 'user')->pluck('target_id')->unique();
        $deptIds = $logs->getCollection()->where('target_type', 'department')->pluck('target_id')->unique();

        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $depts = Department::whereIn('Dept_id', $deptIds)->get(['Dept_id', 'Dept_name'])->keyBy('Dept_id');

        $logs->getCollection()->transform(function (HRAuditTrail $log) use ($users, $depts) {
            $count = (int) $log->affected_count;

            $log->action_label = self::ACTION_LABELS[$log->action] ?? $log->action;
            $log->affected_count = $count;
            $log->is_batch = $count > 1;
            $log->target_label = $log->is_batch
                ? number_format($count).' employee'.($count === 1 ? '' : 's')
                : $this->resolveTargetLabel($log, $users, $depts);
            $log->summary = $this->summarizeDetails($log);

            return $log;
        });

        return $logs;
    }

    /**
     * JSON drill-down for a collapsed batch row: every employee sharing that
     * batch_id, optionally narrowed to the currently-active department
     * filter so the modal's list matches whatever count is shown on screen.
     */
    public function batchEmployees(Request $request, string $batchId): JsonResponse
    {
        $this->authorizeManager($request->user());

        $deptId = $request->integer('dept_id') ?: null;

        $targetIds = HRAuditTrail::where('module', 'shift_management')
            ->where('batch_id', $batchId)
            ->where('target_type', 'user')
            ->pluck('target_id')
            ->unique();

        $employees = User::whereIn('id', $targetIds)
            ->when($deptId, fn ($q) => $q->where('Dept_id', $deptId))
            ->with('department:Dept_id,Dept_name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'Dept_id'])
            ->map(fn (User $u) => [
                'name' => trim("{$u->last_name}, {$u->first_name}"),
                'department' => $u->department->Dept_name ?? '-',
            ])
            ->values();

        return response()->json(['employees' => $employees, 'count' => $employees->count()]);
    }

    private function resolveTargetLabel(HRAuditTrail $log, Collection $users, Collection $depts): string
    {
        return match ($log->target_type) {
            'user' => $users->has($log->target_id)
                ? trim($users[$log->target_id]->last_name.', '.$users[$log->target_id]->first_name)
                : "Employee #{$log->target_id}",
            'department' => $depts->has($log->target_id)
                ? $depts[$log->target_id]->Dept_name
                : "Department #{$log->target_id}",
            'shift' => ($log->details['name'] ?? null) ?: "Shift #{$log->target_id}",
            default => (string) $log->target_id,
        };
    }

    private function summarizeDetails(HRAuditTrail $log): string
    {
        $d = $log->details ?? [];

        return match ($log->action) {
            // work_days alone is enough here: ShiftAssignmentService::assign()
            // always forces it to equal days_of_week whenever the latter is
            // set, so a separate "(scoped: ...)" suffix would just repeat the
            // same label.
            'shift_assigned' => ! empty($d['shift_name'])
                ? "Assigned to {$d['shift_name']} - ".ShiftAssignment::daysOfWeekLabel($d['work_days'] ?? ShiftAssignment::DEFAULT_WORK_DAYS)
                    .(($d['no_break'] ?? false) ? ', no break' : '')
                : 'Reverted to Standard Day',
            'shift_assignment_corrected' => ! empty($d['shift_name'])
                ? "Corrected to {$d['shift_name']} - ".ShiftAssignment::daysOfWeekLabel($d['work_days'] ?? ShiftAssignment::DEFAULT_WORK_DAYS)
                    .(($d['no_break'] ?? false) ? ', no break' : '')
                : 'Corrected to Standard Day',
            'shift_removed' => 'Reverted to Standard Day (bulk)',
            'dtr_exemption_toggled' => ! empty($d['exempt']) ? 'Marked exempt from DTR' : 'Restored to DTR tracking',
            // Three distinct write paths log the same action: store()/storeBulk()
            // (week grid) stamp 'week_start'; applyWeeklyPattern() (date-range
            // panel) stamps 'start_date'/'end_date'; storeSingleDay() (single-day
            // override bar) stamps 'date'/'value' instead - never more than one shape.
            'shift_schedule_updated' => match (true) {
                isset($d['week_start']) => "Week of {$d['week_start']} - {$d['days_changed']} day(s) changed",
                isset($d['date']) => "{$d['date']} - ".$this->describeSingleDayValue($d['value'] ?? null)
                    .(($d['no_break'] ?? false) ? ', no break' : ''),
                default => "{$d['start_date']} to {$d['end_date']} - {$d['days_changed']} day(s) changed",
            },
            'rotation_generated' => "{$d['on_days']}-on / {$d['off_days']}-off, {$d['start_date']} to {$d['end_date']}",
            'shift_template_created', 'shift_template_updated' => "{$d['time_in']}-{$d['time_out']}"
                .(($d['is_global'] ?? true) ? ', Shared' : ', scoped to '.count($d['department_ids'] ?? []).' dept(s)'),
            'shift_template_deleted' => 'Template removed',
            default => '',
        };
    }

    /**
     * Renders storeSingleDay()'s 'value' vocabulary (see ShiftScheduleController)
     * into a human-readable label for the single-day override log summary.
     */
    private function describeSingleDayValue(mixed $value): string
    {
        return match ($value) {
            null, '', 'default' => 'Reverted to default',
            'rest' => 'Rest Day',
            'field_work' => 'Field Work',
            'wfh' => 'Work From Home',
            'standard' => 'Standard Day',
            default => is_numeric($value) ? "Shift #{$value}" : (string) $value,
        };
    }
}
