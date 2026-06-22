<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Shift;
use App\Models\User;
use App\Services\PersonnelLogImportService;
use App\Support\RoleNormalizer;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Time Keeper screen for assigning a work-shift template to each employee.
 *
 * An employee with no shift assigned (shift_id = null) follows the global
 * standard-day shift from the settings table. Assigning or changing a shift
 * recomputes that employee's existing DTRs so stored penalties reflect the new
 * shift.
 */
class EmployeeScheduleController extends Controller
{
    /** Roles allowed to assign shifts. */
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    public function __construct(
        private readonly PersonnelLogImportService $importService,
    ) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $departments = Department::orderBy('Dept_name')->get();
        $deptId = $request->integer('dept_id') ?: null;
        $shiftId = $request->integer('shift_id') ?: null;
        $search = trim((string) $request->query('search', ''));

        $shifts = Shift::where('is_active', true)->orderBy('name')->get();

        $employees = User::query()
            ->when($deptId, fn ($q) => $q->where('Dept_id', $deptId))
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search): void {
                $sub->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('EmpNo', 'like', '%'.$search.'%');
            }))
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(25, ['id', 'first_name', 'last_name', 'Dept_id', 'shift_id'])
            ->withQueryString();

        return view('attendance.schedules.index', compact('departments', 'shifts', 'employees', 'deptId', 'shiftId', 'search'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManager($request->user());

        $validated = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        $user->update(['shift_id' => $validated['shift_id'] ?? null]);

        $this->recomputeEmployee($user);

        $name = trim("{$user->first_name} {$user->last_name}");
        $label = $user->shift_id ? ($user->shift()->value('name') ?? 'shift') : 'Standard Day';

        return back()->with('schedule_status', "{$name} assigned to {$label}. Existing time records were recomputed.");
    }

    /**
     * Rebuild the employee's DTR rows across their full attendance-log range so
     * stored late/undertime reflect the new shift. One employee's range is
     * bounded, so run synchronously.
     */
    private function recomputeEmployee(User $user): void
    {
        $range = AttendanceLog::where('user_id', $user->id)
            ->selectRaw('MIN(logdate) as min_d, MAX(logdate) as max_d')
            ->first();

        if ($range === null || $range->min_d === null) {
            return;
        }

        $this->importService->recomputeDtr(
            $user,
            Carbon::parse($range->min_d)->toDateString(),
            Carbon::parse($range->max_d)->toDateString(),
        );
    }
}
