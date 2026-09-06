<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeImportTemplate;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\User;
use App\Notifications\EmployeeDefaultPasswordNotification;
use App\Services\EmployeeAssignmentService;
use App\Support\HrisConstants;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class RecordsManagerController extends Controller
{
    public function __construct(private EmployeeAssignmentService $employeeAssignmentService) {}

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

    public function index(Request $request)
    {
        $this->ensureRecordsManager($request);

        $search = trim((string) $request->query('search', ''));
        $statusFilter = trim((string) $request->query('status', ''));
        $departmentFilter = trim((string) $request->query('department', ''));
        $employeeTypeFilter = trim((string) $request->query('employee_type', ''));

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

        if (in_array($employeeTypeFilter, HrisConstants::EMPLOYEE_TYPES, true)) {
            $employeesQuery->where('employee_type', $employeeTypeFilter);
        }

        $employees = $employeesQuery->get(['id', 'last_name', 'first_name', 'middle_name', 'email', 'EmpNo', 'designation', 'Dept_id', 'Status', 'employee_type', 'access_level', 'date_hired']);

        $departments = Department::query()->orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);

        $totalEmployees = User::count();
        $activeEmployees = User::active()->count();

        $maxSequentialByType = User::whereNotNull('EmpNo')
            ->whereNotNull('employee_type')
            ->whereRaw("EmpNo REGEXP '^[0-9]{7}$'")
            ->selectRaw('employee_type, MAX(CAST(SUBSTRING(EmpNo, 3) AS UNSIGNED)) as max_seq')
            ->groupBy('employee_type')
            ->pluck('max_seq', 'employee_type');

        $nextSequentialByType = [];
        foreach (HrisConstants::EMPLOYEE_TYPES as $type) {
            $nextSequentialByType[$type] = str_pad((int) ($maxSequentialByType[$type] ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        }

        return view('dashboards.records-manager-employees', [
            'user' => $request->user(),
            'employees' => $employees,
            'departments' => $departments,
            'accessLevels' => array_keys(self::ROLE_VIEW_MAP),
            'employeeTypes' => HrisConstants::EMPLOYEE_TYPES,
            'statusSummary' => [
                'total' => $totalEmployees,
                'active' => $activeEmployees,
                'inactive' => $totalEmployees - $activeEmployees,
            ],
            'search' => $search,
            'statusFilter' => $statusFilter,
            'departmentFilter' => $departmentFilter,
            'employeeTypeFilter' => $employeeTypeFilter,
            'nextSequentialByType' => $nextSequentialByType,
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
            'Status' => ['nullable', Rule::in(User::STATUSES)],
            'employee_type' => ['nullable', Rule::in(HrisConstants::EMPLOYEE_TYPES)],
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
            'Status' => ['nullable', Rule::in(User::STATUSES)],
            'employee_type' => ['nullable', Rule::in(HrisConstants::EMPLOYEE_TYPES)],
            'access_level' => ['required', Rule::in($allowedAccessLevels)],
            'date_hired' => ['required', 'date'],
        ]);

        if ($this->hasDuplicateEmployeeName($validated['last_name'], $validated['first_name'], $user->id)) {
            return response()->json(['success' => false, 'message' => 'A record with the same Last Name and First Name already exists.'], 422);
        }

        $fullName = $this->buildEmployeeName($validated['last_name'], $validated['first_name'], $validated['middle_name'] ?? null);
        $previousStatus = $user->Status;
        $newStatus = $validated['Status'] ?? null;

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
            'Status' => $newStatus,
            'employee_type' => $validated['employee_type'] ?? null,
            'access_level' => $validated['access_level'],
            'date_hired' => $validated['date_hired'],
        ])->save();

        // A user who no longer holds the department head / administrative officer
        // role must not keep being the notification recipient for a department.
        $newRole = strtolower(trim((string) $validated['access_level']));
        if ($newRole !== 'department head') {
            Department::where('department_head_id', $user->id)->update(['department_head_id' => null]);
        }
        if ($newRole !== 'administrative officer') {
            Department::where('admin_officer_id', $user->id)->update(['admin_officer_id' => null]);
        }

        if ($newStatus !== $previousStatus) {
            // A manual status change always supersedes job-order:deactivate-expired's
            // own bookkeeping, whichever direction it goes.
            $user->forceFill(['job_order_auto_deactivated_at' => null])->save();

            HRAuditTrail::create([
                'actor_user_id' => $request->user()?->id,
                'module' => 'records',
                'action' => 'status_changed',
                'target_type' => User::class,
                'target_id' => $user->id,
                'details' => [
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'employee_name' => $user->name,
                    'employee_no' => $user->EmpNo,
                ],
            ]);

            if ($user->isInactive() || $user->isSeparated()) {
                $this->employeeAssignmentService->endActiveAssignmentForStatusChange($user->id, (string) $newStatus);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Employee record updated successfully.']);
        }

        return redirect()->back()->with(['status' => 'success', 'message' => 'Employee record updated successfully.']);
    }

    public function destroy(Request $request, User $user)
    {
        $this->ensureRecordsManager($request);

        $blockingCategories = $this->userHistoryCategories($user);

        if (! empty($blockingCategories)) {
            HRAuditTrail::create([
                'actor_user_id' => $request->user()->id,
                'module' => 'records',
                'action' => 'employee_delete_blocked',
                'target_type' => 'user',
                'target_id' => $user->id,
                'details' => [
                    'emp_no' => $user->EmpNo,
                    'name' => $user->name,
                    'blocking_categories' => $blockingCategories,
                ],
            ]);

            $message = 'This employee has recorded '.implode(', ', $blockingCategories)
                .' history and cannot be permanently deleted. Set their status to Separated instead.';

            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => $message], 422);
            }

            return redirect()->back()->with(['status' => 'error', 'message' => $message]);
        }

        DB::transaction(function () use ($request, $user) {
            HRAuditTrail::create([
                'actor_user_id' => $request->user()->id,
                'module' => 'records',
                'action' => 'employee_deleted',
                'target_type' => 'user',
                'target_id' => $user->id,
                'details' => [
                    'emp_no' => $user->EmpNo,
                    'name' => $user->name,
                    'access_level' => $user->access_level,
                ],
            ]);

            $user->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Employee record deleted.']);
        }

        return redirect()->back()->with(['status' => 'success', 'message' => 'Employee record deleted.']);
    }

    /**
     * Which categories of protected history block a permanent delete - payroll,
     * attendance, and leave records in particular carry COA/legal retention weight
     * and must never be silently destroyed by a hard delete. Returns the labels of
     * every category with at least one matching row, empty when the employee is
     * genuinely history-free and safe to hard-delete.
     */
    private function userHistoryCategories(User $user): array
    {
        $checks = [
            'payroll' => DB::table('payroll_details')->where('employee_id', $user->id)->exists()
                || DB::table('employee_earnings')->where('employee_id', $user->id)->exists()
                || DB::table('employee_deductions')->where('employee_id', $user->id)->exists()
                || DB::table('withholding_taxes')->where('employee_id', $user->id)->exists()
                || DB::table('loans')->where('employee_id', $user->id)->exists()
                || DB::table('payslips')->where('employee_id', $user->id)->exists(),
            'plantilla assignment' => DB::table('employee_assignments')->where('employee_id', $user->id)->exists(),
            'attendance' => DB::table('attendance_logs')->where('user_id', $user->id)->exists()
                || DB::table('dtrs')->where('employee_id', $user->id)->exists()
                || DB::table('dtr_excuses')->where('user_id', $user->id)->exists()
                || DB::table('dtr_exemption_periods')->where('user_id', $user->id)->exists()
                || DB::table('locators')->where('user_id', $user->id)->exists()
                || DB::table('eta')->where('user_id', $user->id)->exists(),
            // Deleting the submitter of a batch would cascade the whole batch header
            // away, silently destroying OTHER employees' flagged items along with it -
            // not just this user's own data.
            'attendance adjustment' => DB::table('attendance_adjustment_submissions')->where('submitted_by', $user->id)->exists()
                || DB::table('attendance_adjustment_submission_items')->where('user_id', $user->id)->exists(),
            // leave_requests.user_id is a bare RESTRICT FK (blocks the delete on its
            // own, but with an uncaught 500 - checking it here gives a clean message
            // instead). approved_by is a separate, SET NULL column added later and
            // needs no check; recommended_by/finalized_by/approver_id from an earlier
            // schema no longer exist at all (confirmed against the live schema, not
            // just migration files, since this table has had several since-superseded
            // approval-column migrations). leave_balances is deliberately NOT checked
            // here - UserObserver::created() auto-creates one for every user
            // unconditionally, so its mere existence says nothing about real history.
            'leave' => DB::table('leave_requests')->where('user_id', $user->id)->exists()
                || DB::table('leave_ledger')->where('user_id', $user->id)->exists()
                || DB::table('monthly_attendance')->where('user_id', $user->id)->exists()
                || DB::table('leave_dates')->where('cancelled_by', $user->id)->exists(),
            'job order appointment' => DB::table('job_order_appointments')->where('user_id', $user->id)->exists(),
            'uniform inspection' => DB::table('uniform_inspection_details')->where('employee_id', $user->id)->exists()
                || DB::table('uniform_inspection_deductions')->where('employee_id', $user->id)->exists(),
            'disciplinary notice' => DB::table('habitual_violation_notices')->where('employee_id', $user->id)->exists(),
            'personal data sheet' => DB::table('user_pds')->where('user_id', $user->id)->exists(),
            'oic assignment' => DB::table('oic_assignments')
                ->where('user_id', $user->id)
                ->orWhere('appointed_by', $user->id)
                ->exists(),
            'travel order' => DB::table('travel_orders')
                ->where('recommender', $user->id)
                ->orWhere('created_by', $user->id)
                ->exists()
                || (! empty($user->EmpNo) && DB::table('travel_order_employees')->where('emp_no', $user->EmpNo)->exists()),
            'shift management grant' => DB::table('shift_management_grants')
                ->where('granted_by', $user->id)
                ->orWhere('revoked_by', $user->id)
                ->exists(),
            'e-signature signing' => DB::table('esignature_signings')->where('requested_by', $user->id)->exists(),
            // Found by re-verifying §2.1 directly against live information_schema
            // (not migration files) rather than trusting the prior pass's own
            // "full audit" claim - all three are real CASCADE FKs to users.id.
            'shift management' => DB::table('shift_assignments')->where('user_id', $user->id)->exists()
                || DB::table('employee_shift_schedules')->where('user_id', $user->id)->exists(),
            'e-signature configuration' => DB::table('esignature_settings')->where('user_id', $user->id)->exists(),
            'export jobs' => DB::table('export_jobs')->where('user_id', $user->id)->exists(),
        ];

        return array_keys(array_filter($checks));
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

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successfully.',
                'temporary_password' => $temporaryPassword,
            ]);
        }

        return redirect()->back()->with(['status' => 'success', 'message' => 'Password reset successfully. Temporary password: '.$temporaryPassword]);
    }

    public function downloadImportTemplate()
    {
        return Excel::download(new EmployeeImportTemplate, 'employee_import_template.xlsx');
    }

    public function import(Request $request): JsonResponse
    {
        $this->ensureRecordsManager($request);

        $request->validate([
            'import_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:2048'],
        ]);

        $rows = Excel::toCollection(null, $request->file('import_file'))->first();

        if ($rows === null || $rows->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'The uploaded file is empty.'], 422);
        }

        $allowedAccessLevels = array_keys(self::ROLE_VIEW_MAP);
        $departments = Department::query()->pluck('Dept_id', 'Dept_name');
        $departmentLookup = $departments->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id]);

        $maxSequentialByType = User::whereNotNull('EmpNo')
            ->whereNotNull('employee_type')
            ->whereRaw("EmpNo REGEXP '^[0-9]{7}$'")
            ->selectRaw('employee_type, MAX(CAST(SUBSTRING(EmpNo, 3) AS UNSIGNED)) as max_seq')
            ->groupBy('employee_type')
            ->pluck('max_seq', 'employee_type');

        $sequentialCounters = [];
        foreach (HrisConstants::EMPLOYEE_TYPES as $type) {
            $sequentialCounters[$type] = (int) ($maxSequentialByType[$type] ?? 0);
        }

        $imported = 0;
        $failed = [];
        $warnings = [];

        // Skip the header row (index 0). Collection::skip() preserves original
        // keys, so $index is already the pre-skip position (1-based spreadsheet
        // row number requires only +1, not +2).
        foreach ($rows->skip(1) as $index => $row) {
            $rowNumber = $index + 1;

            $empNoInput = trim((string) ($row[0] ?? ''));
            $lastName = strtoupper(trim((string) ($row[1] ?? '')));
            $firstName = strtoupper(trim((string) ($row[2] ?? '')));
            $middleName = strtoupper(trim((string) ($row[3] ?? '')));
            $email = strtolower(trim((string) ($row[4] ?? '')));
            $designation = trim((string) ($row[5] ?? ''));
            $deptName = trim((string) ($row[6] ?? ''));
            $dateHired = trim((string) ($row[7] ?? ''));
            $empType = trim((string) ($row[8] ?? ''));
            $accessLevel = strtolower(trim((string) ($row[9] ?? '')));

            if ($empNoInput === '' && $lastName === '' && $firstName === '' && $middleName === ''
                && $email === '' && $designation === '' && $deptName === '' && $dateHired === ''
                && $empType === '' && $accessLevel === '') {
                continue;
            }

            $rowErrors = [];

            if ($lastName === '') {
                $rowErrors[] = 'Last Name is required.';
            }
            if ($firstName === '') {
                $rowErrors[] = 'First Name is required.';
            }
            if ($email === '') {
                $rowErrors[] = 'Email is required.';
            }
            if ($dateHired === '') {
                $rowErrors[] = 'Date Hired is required.';
            }
            if ($empType === '') {
                $rowErrors[] = 'Employee Type is required.';
            }
            if ($accessLevel === '') {
                $rowErrors[] = 'Access Level is required.';
            }

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = 'Email is not valid.';
            }

            if ($empType !== '' && ! in_array($empType, HrisConstants::EMPLOYEE_TYPES, true)) {
                $rowErrors[] = 'Employee Type must be one of: '.implode(', ', HrisConstants::EMPLOYEE_TYPES).'.';
            }

            if ($accessLevel !== '' && ! in_array($accessLevel, $allowedAccessLevels, true)) {
                $rowErrors[] = 'Access Level must be one of: '.implode(', ', $allowedAccessLevels).'.';
            }

            $parsedDateHired = $dateHired !== '' ? $this->parseImportDate($dateHired) : null;
            if ($dateHired !== '' && $parsedDateHired === null) {
                $rowErrors[] = 'Date Hired is not a valid date.';
            }

            if ($email !== '' && User::where('email', $email)->exists()) {
                $rowErrors[] = 'Email is already in use.';
            }

            if ($empNoInput !== '' && User::where('EmpNo', $empNoInput)->exists()) {
                $rowErrors[] = 'Employee number is already in use.';
            }

            if (! empty($rowErrors)) {
                $failed[] = ['row' => $rowNumber, 'errors' => $rowErrors];

                continue;
            }

            $deptId = null;
            if ($deptName !== '') {
                $deptId = $departmentLookup->get(strtolower($deptName));
                if ($deptId === null) {
                    $warnings[] = ['row' => $rowNumber, 'message' => "Department '{$deptName}' was not found - employee created without department assignment."];
                }
            }

            $empNoWasProvided = $empNoInput !== '';
            if ($empNoWasProvided) {
                $empNo = $empNoInput;
            } else {
                // Generate EmpNo: YY (from date_hired year) + 5-digit sequential per type
                $year = $parsedDateHired->format('y');
                $sequentialCounters[$empType]++;
                $empNo = $year.str_pad($sequentialCounters[$empType], 5, '0', STR_PAD_LEFT);
            }

            $fullName = $this->buildEmployeeName($lastName, $firstName, $middleName ?: null);
            $emailName = (string) strstr($email, '@', true);
            $defaultPassword = 'HRIS-'.Str::upper(Str::random(8));

            $newUser = new User;
            $newUser->forceFill([
                'name' => $fullName,
                'email' => $email,
                'UserName' => $emailName !== '' ? $emailName : $email,
                'AcctName' => $fullName,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $middleName ?: null,
                'EmpNo' => $empNo,
                'designation' => $designation ?: null,
                'Dept_id' => $deptId,
                'Status' => 'Active',
                'employee_type' => $empType,
                'access_level' => $accessLevel,
                'password' => Hash::make($defaultPassword),
                'force_password_change' => true,
                'date_hired' => $parsedDateHired->format('Y-m-d'),
            ]);

            try {
                $newUser->save();
            } catch (UniqueConstraintViolationException) {
                if (! $empNoWasProvided) {
                    $sequentialCounters[$empType]--;
                }
                $failed[] = ['row' => $rowNumber, 'errors' => ['A duplicate email or employee number was detected.']];

                continue;
            } catch (Throwable) {
                if (! $empNoWasProvided) {
                    $sequentialCounters[$empType]--;
                }
                $failed[] = ['row' => $rowNumber, 'errors' => ['An unexpected error occurred while saving this record.']];

                continue;
            }

            $newUser->notify(new EmployeeDefaultPasswordNotification($defaultPassword));
            $imported++;
        }

        return response()->json([
            'imported' => $imported,
            'failed' => $failed,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Parses a "Date Hired" cell value that may arrive as an ISO string, a
     * day-first slash/dash date, an Excel serial number, or another common
     * format depending on how the source spreadsheet formatted the cell.
     */
    private function parseImportDate(string $value): ?Carbon
    {
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } catch (Throwable) {
                return null;
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false) {
                return $date->startOfDay();
            }
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
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
