<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Notifications\EmployeeDefaultPasswordNotification;
use App\Notifications\PasswordResetByAdminNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class RecordsManagerController extends Controller
{
    private const ROLE_VIEW_MAP = [
        'employee' => 'dashboards.employee',
        'department head' => 'dashboards.department-head',
        'administrative officer' => 'dashboards.administrative-officer',
        'hr manager' => 'hr-manager.dashboard',
        'mayor' => 'dashboards.mayor',
        'leave manager' => 'dashboards.leave-manager',
        'time keeper' => 'dashboards.time-keeper',
        'payroll manager' => 'dashboards.payroll-manager',
        'records manager' => 'dashboards.records-manager',
        'front desk' => 'dashboards.front-desk',
    ];

    private const EMPLOYEE_TYPES = [
        'Permanent',
        'Elected Officials',
        'Co-Terminus',
        'Casual',
        'Job Orders',
        'Contractual',
    ];

    public function index(Request $request)
    {
        $this->ensureRecordsManager($request);

        $search = trim((string) $request->query('search', ''));
        $statusFilter = trim((string) $request->query('status', ''));
        $departmentFilter = trim((string) $request->query('department', ''));

        $employeesQuery = User::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name');

        if ($search !== '') {
            $employeesQuery->where(function ($query) use ($search): void {
                $query
                    ->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('EmpNo', 'like', '%'.$search.'%');
            });
        }

        if (in_array($statusFilter, ['Active', 'Inactive', 'Separated'], true)) {
            $employeesQuery->where('Status', $statusFilter);
        }

        if ($departmentFilter !== '') {
            $employeesQuery->where('Dept_id', $departmentFilter);
        }

        $employees = $employeesQuery->get(['id', 'last_name', 'first_name', 'middle_name', 'email', 'EmpNo', 'designation', 'Dept_id', 'Status', 'employee_type', 'access_level', 'date_hired']);

        $departments = Department::query()->orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);

        $totalEmployees = User::count();
        $activeEmployees = User::where('Status', 'Active')->count();

        return view('dashboards.records-manager-employees', [
            'user' => $request->user(),
            'employees' => $employees,
            'departments' => $departments,
            'accessLevels' => array_keys(self::ROLE_VIEW_MAP),
            'employeeTypes' => self::EMPLOYEE_TYPES,
            'statusSummary' => [
                'total' => $totalEmployees,
                'active' => $activeEmployees,
                'inactive' => $totalEmployees - $activeEmployees,
            ],
            'search' => $search,
            'statusFilter' => $statusFilter,
            'departmentFilter' => $departmentFilter,
        ]);
    }

    public function fetchEmployees(Request $request): JsonResponse
    {
        $this->ensureRecordsManager($request);

        $employees = User::query()->orderBy('last_name')->get(['id', 'last_name', 'first_name', 'middle_name', 'email', 'EmpNo', 'designation', 'Dept_id', 'Status', 'employee_type', 'access_level', 'date_hired']);

        return response()->json(['employees' => $employees]);
    }

    public function store(Request $request)
    {
        $this->ensureRecordsManager($request);

        $allowedAccessLevels = array_keys(self::ROLE_VIEW_MAP);
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'EmpNo' => ['nullable', 'string', 'max:255', Rule::unique('users', 'EmpNo')],
            'designation' => ['nullable', 'string', 'max:255'],
            'Dept_id' => ['nullable', 'exists:departments,Dept_id'],
            'Status' => ['nullable', 'in:Active,Inactive,Separated'],
            'employee_type' => ['nullable', Rule::in(self::EMPLOYEE_TYPES)],
            'access_level' => ['required', Rule::in($allowedAccessLevels)],
            'date_hired' => ['required', 'date'],
        ]);

        if ($this->hasDuplicateEmployeeName($validated['last_name'], $validated['first_name'])) {
            return response()->json(['success' => false, 'message' => 'A record with the same Last Name and First Name already exists.'], 422);
        }

        $fullName = $this->buildEmployeeName($validated['last_name'], $validated['first_name'], $validated['middle_name'] ?? null);
        $emailName = (string) strstr($validated['email'], '@', true);
        $defaultPassword = 'HRIS-'.Str::upper(Str::random(8));

        $newUser = new User;
        $newUser->forceFill([
            'name' => $fullName,
            'email' => $validated['email'],
            'UserName' => $emailName !== '' ? $emailName : $validated['email'],
            'AcctName' => $fullName,
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'EmpNo' => $validated['EmpNo'] ?? null,
            'designation' => $validated['designation'] ?? null,
            'Dept_id' => $validated['Dept_id'] ?? null,
            'Status' => $validated['Status'] ?? 'Active',
            'employee_type' => $validated['employee_type'] ?? null,
            'access_level' => $validated['access_level'],
            'password' => Hash::make($defaultPassword),
            'force_password_change' => true,
            'date_hired' => $validated['date_hired'],
        ]);

        try {
            $newUser->notify(new EmployeeDefaultPasswordNotification($defaultPassword));
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'Could not send credentials email. Account not created.'], 500);
        }

        try {
            $newUser->save();
        } catch (UniqueConstraintViolationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'A duplicate email or employee number was detected.'], 422);
            }

            return redirect()->back()
                ->withErrors(['email' => 'This email or employee number is already in use by another account.'])
                ->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'New employee account created. Default password sent.']);
        }

        return redirect()->back()->with(['status' => 'success', 'message' => 'New employee account created. Default password sent.']);
    }

    public function update(Request $request, User $user)
    {
        $this->ensureRecordsManager($request);

        $allowedAccessLevels = array_keys(self::ROLE_VIEW_MAP);
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'EmpNo' => ['nullable', 'string', 'max:255', Rule::unique('users', 'EmpNo')->ignore($user->id)],
            'designation' => ['nullable', 'string', 'max:255'],
            'Dept_id' => ['nullable', 'exists:departments,Dept_id'],
            'Status' => ['nullable', 'in:Active,Inactive,Separated'],
            'employee_type' => ['nullable', Rule::in(self::EMPLOYEE_TYPES)],
            'access_level' => ['required', Rule::in($allowedAccessLevels)],
            'date_hired' => ['required', 'date'],
        ]);

        if ($this->hasDuplicateEmployeeName($validated['last_name'], $validated['first_name'], $user->id)) {
            return response()->json(['success' => false, 'message' => 'A record with the same Last Name and First Name already exists.'], 422);
        }

        $fullName = $this->buildEmployeeName($validated['last_name'], $validated['first_name'], $validated['middle_name'] ?? null);

        $user->forceFill([
            'name' => $fullName,
            'AcctName' => $fullName,
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $validated['email'],
            'EmpNo' => $validated['EmpNo'] ?? null,
            'designation' => $validated['designation'] ?? null,
            'Dept_id' => $validated['Dept_id'] ?? null,
            'Status' => $validated['Status'] ?? null,
            'employee_type' => $validated['employee_type'] ?? null,
            'access_level' => $validated['access_level'],
            'date_hired' => $validated['date_hired'],
        ])->save();

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Employee record updated successfully.']);
        }

        return redirect()->back()->with(['status' => 'success', 'message' => 'Employee record updated successfully.']);
    }

    public function destroy(Request $request, User $user)
    {
        $this->ensureRecordsManager($request);

        $user->delete();

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Employee record deleted.']);
        }

        return redirect()->back()->with(['status' => 'success', 'message' => 'Employee record deleted.']);
    }

    public function resetPassword(Request $request, int $id)
    {
        $this->ensureRecordsManager($request);

        $employee = User::findOrFail($id);
        $temporaryPassword = 'HRIS-'.Str::upper(Str::random(8));

        $employee->forceFill([
            'password' => Hash::make($temporaryPassword),
            'force_password_change' => true,
        ])->save();

        try {
            $employee->notify(new PasswordResetByAdminNotification($temporaryPassword));
        } catch (Throwable $exception) {
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Password was reset but the email could not be sent. Please inform the employee manually.'], 500);
            }

            return redirect()->back()->with(['status' => 'error', 'message' => 'Password was reset but the email could not be sent.']);
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Password reset successfully. A temporary password has been sent to '.$employee->email.'.']);
        }

        return redirect()->back()->with(['status' => 'success', 'message' => 'Password reset successfully. A temporary password has been sent to '.$employee->email.'.']);
    }

    private function ensureRecordsManager(Request $request): void
    {
        $role = $this->normalizeRole((string) $request->user()->access_level);
        abort_unless($role === 'records manager', 403, 'Only Records Manager can access this section.');
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function buildEmployeeName(string $lastName, string $firstName, ?string $middleName = null): string
    {
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $middleName = trim((string) $middleName);

        $givenName = trim($firstName.' '.$middleName);

        return trim($lastName.', '.$givenName, ', ');
    }

    private function hasDuplicateEmployeeName(string $lastName, string $firstName, ?int $ignoreUserId = null): bool
    {
        $query = User::query()->where('last_name', $lastName)->where('first_name', $firstName);
        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->exists();
    }
}
