<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\User;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Time Keeper / HR Manager company-wide shift oversight screen: a full
 * chronological log of every shift-related change - template edits,
 * assignments, exemption toggles, schedule/rotation changes, and access
 * grants/revokes. Not extended to Department Head/Administrative Officer -
 * they already have their own dept-scoped Monitoring Matrix for
 * tardiness/undertime.
 */
class ShiftLogController extends Controller
{
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    private const ACTION_LABELS = [
        'access_granted' => 'Access Granted',
        'access_revoked' => 'Access Revoked',
        'shift_assigned' => 'Shift Assigned',
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

    private function buildLogPage(?\Closure $query = null, string $pageName = 'log_page')
    {
        $logs = HRAuditTrail::where('module', 'shift_management')
            ->when($query, fn ($q) => $query($q))
            ->with('actor:id,first_name,last_name,name')
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], $pageName)
            ->withQueryString();

        $userIds = $logs->getCollection()->where('target_type', 'user')->pluck('target_id')->unique();
        $deptIds = $logs->getCollection()->where('target_type', 'department')->pluck('target_id')->unique();

        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $depts = Department::whereIn('Dept_id', $deptIds)->get(['Dept_id', 'Dept_name'])->keyBy('Dept_id');

        $logs->getCollection()->transform(function (HRAuditTrail $log) use ($users, $depts) {
            $log->action_label = self::ACTION_LABELS[$log->action] ?? $log->action;
            $log->target_label = $this->resolveTargetLabel($log, $users, $depts);
            $log->summary = $this->summarizeDetails($log);

            return $log;
        });

        return $logs;
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
            'shift_assigned' => ! empty($d['shift_name']) ? "Assigned to {$d['shift_name']}" : 'Reverted to Standard Day',
            'dtr_exemption_toggled' => ! empty($d['exempt']) ? 'Marked exempt from DTR' : 'Restored to DTR tracking',
            'shift_schedule_updated' => "Week of {$d['week_start']} - {$d['days_changed']} day(s) changed",
            'rotation_generated' => "{$d['on_days']}-on / {$d['off_days']}-off, {$d['start_date']} to {$d['end_date']}",
            'shift_template_created', 'shift_template_updated' => "{$d['time_in']}-{$d['time_out']}".(($d['no_break'] ?? false) ? ', no break' : ''),
            'shift_template_deleted' => 'Template removed',
            default => '',
        };
    }
}
