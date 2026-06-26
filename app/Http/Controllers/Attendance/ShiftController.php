<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Shift;
use App\Models\User;
use App\Services\PersonnelLogImportService;
use App\Support\RoleNormalizer;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Time Keeper management of named work-shift templates (e.g. "Standard Day",
 * "Night", "Mid"). Employees are assigned a template via EmployeeScheduleController.
 */
class ShiftController extends Controller
{
    /** Roles allowed to manage shift templates. */
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

        $shifts = Shift::withCount('employees')->orderBy('name')->get();

        return view('attendance.shifts.index', compact('shifts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager($request->user());

        $data = $this->validateShift($request);
        Shift::create($data);

        return back()->with('shift_status', "Shift template \"{$data['name']}\" created.");
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorizeManager($request->user());

        $data = $this->validateShift($request);
        $shift->update($data);

        // Times changed - recompute every employee currently on this shift.
        $shift->employees()->each(fn (User $u) => $this->recomputeEmployee($u));

        return back()->with('shift_status', "Shift template \"{$shift->name}\" updated and affected DTRs recomputed.");
    }

    public function destroy(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorizeManager($request->user());

        if ($shift->employees()->exists()) {
            return back()->with('shift_error', "Cannot delete \"{$shift->name}\" - employees are still assigned to it.");
        }

        $shift->delete();

        return back()->with('shift_status', 'Shift template deleted.');
    }

    /**
     * @return array{name: string, time_in: string, break_out: string|null, break_in: string|null, time_out: string, crosses_midnight: bool, no_break: bool, is_active: bool}
     */
    private function validateShift(Request $request): array
    {
        $noBreak = (bool) $request->input('no_break', false);

        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'time_in' => ['required', 'date_format:H:i'],
            'break_out' => $noBreak ? ['nullable'] : ['required', 'date_format:H:i'],
            'break_in' => $noBreak ? ['nullable'] : ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i'],
        ]);

        return [
            'name' => $v['name'],
            'time_in' => $v['time_in'],
            'break_out' => $noBreak ? null : $v['break_out'],
            'break_in' => $noBreak ? null : $v['break_in'],
            'time_out' => $v['time_out'],
            'crosses_midnight' => Shift::isCrossMidnight($v['time_in'], $v['time_out']),
            'no_break' => $noBreak,
            'is_active' => true,
        ];
    }

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
