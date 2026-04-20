<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Eta;
use App\Models\HRAuditTrail;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\EtaNotification;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Carbon;

class EtaController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Eta::where('user_id', $user->id)->orderBy('departure_date', 'desc');

        $filter = $request->query('filter');
        if ($filter === 'weekly') {
            $query->whereBetween('departure_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($filter === 'monthly') {
            $query->whereMonth('departure_date', now()->month)->whereYear('departure_date', now()->year);
        }

        $etas = $query->paginate(10);

        return view('employee.ETA', compact('etas'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'departure_date' => 'required|date|after_or_equal:today',
            'arrival_date' => 'nullable|date|after_or_equal:departure_date',
            'destination' => 'required|string|max:255',
            'purpose' => ['required', 'string', 'in:Audit-Inspection-Licensing,Client Support,Conference,Construction Repair Maintenance,Economic Development,Legal-Law Enforcement,Legislator,Meeting,Training,Seminar,General Expense/Other'],
            'purpose_details' => 'nullable|string|max:1000',
        ]);

        $data['user_id'] = Auth::id();
        $eta = Eta::create($data + ['status' => 'pending']);

        // Determine department head and send notification
        $employee = User::find($eta->user_id);
        $departmentName = null;
        $departmentHead = null;
        if ($employee && !empty($employee->Dept_id)) {
            $department = Department::find($employee->Dept_id);
            if ($department) {
                $departmentName = $department->Dept_name ?? null;
                if (!empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $departmentHead = User::where('EmpNo', $department->EmpNo)->first();
                }
            }
        }

        // attach department name for email template
        if ($employee) {
            $employee->department_name = $departmentName;
            if ($departmentHead) {
                $parts = [];
                if (!empty($departmentHead->first_name)) $parts[] = $departmentHead->first_name;
                if (!empty($departmentHead->middle_name)) $parts[] = $departmentHead->middle_name;
                if (!empty($departmentHead->last_name)) $parts[] = $departmentHead->last_name;
                if (empty($parts) && !empty($departmentHead->name)) $parts[] = $departmentHead->name;
                $employee->dept_head_name = implode(' ', $parts);
            }
        }

        if ($departmentHead && !empty($departmentHead->email)) {
            try {
                Mail::to($departmentHead->email)
                    ->cc($employee->email ?? null)
                    ->queue(new EtaNotification($employee, $eta));
            } catch (\Exception $ex) {
                // do not block on mail failure; consider logging
            }
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'ETA filed successfully.',
                'redirect' => route('dashboard.employee.eta')
            ]);
        }

        return redirect()->route('dashboard.employee.eta')->with('success', 'ETA filed successfully.');
    }

    public function show(Eta $eta)
    {
        $this->authorize('view', $eta);

        $deptHeadName = null;
        $owner = User::find($eta->user_id);
        if ($owner && !empty($owner->Dept_id)) {
            $department = Department::find($owner->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }

        return view('employee.eta-show', compact('eta', 'deptHeadName'));
    }

    public function print(Request $request)
    {
        $user = Auth::user();
        $filter = $request->query('filter');
        $query = Eta::where('user_id', $user->id)->orderBy('departure_date', 'desc');
        if ($filter === 'weekly') {
            $query->whereBetween('departure_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($filter === 'monthly') {
            $query->whereMonth('departure_date', now()->month)->whereYear('departure_date', now()->year);
        }

        $etas = $query->get();

        $deptHeadName = null;
        if ($user && !empty($user->Dept_id)) {
            $department = Department::find($user->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }

        return view('employee.eta-print', compact('etas', 'filter', 'deptHeadName'));
    }

    public function printSingle(Eta $eta)
    {
        $user = Auth::user();

        $allowed = false;
        if ($eta->user_id === $user->id) {
            $allowed = true;
        } else {
            $owner = $eta->user;
            $deptHeadUser = null;
            if ($owner && !empty($owner->Dept_id)) {
                $department = Department::find($owner->Dept_id);
                if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $deptHeadUser = User::where('EmpNo', $department->EmpNo)->first();
                }
            } elseif ($owner && !empty($owner->EmpNo)) {
                $department = Department::where('EmpNo', $owner->EmpNo)->first();
                if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $deptHeadUser = User::where('EmpNo', $department->EmpNo)->first();
                }
            }

            if ($deptHeadUser && $deptHeadUser->id === $user->id) {
                $allowed = true;
            }

            $role = strtolower(trim((string)$user->access_level));
            if ($role === 'administrative officer') {
                $allowed = true;
            }
        }

        if (! $allowed) {
            abort(403);
        }

        if ($eta->status !== 'approved') {
            abort(403);
        }

        // Use the ETA owner (not the authenticated user) for printed applicant details
        $owner = $eta->user ?? User::find($eta->user_id);

        $fullNameParts = [];
        if ($owner) {
            if (!empty($owner->first_name)) $fullNameParts[] = $owner->first_name;
            if (!empty($owner->middle_name)) $fullNameParts[] = $owner->middle_name;
            if (!empty($owner->last_name)) $fullNameParts[] = $owner->last_name;
            if (empty($fullNameParts) && !empty($owner->name)) $fullNameParts[] = $owner->name;
        }
        $fullName = implode(' ', $fullNameParts);

        $departure = $eta->departure_date ? Carbon::parse($eta->departure_date)->toFormattedDateString() : '';
        $arrival = $eta->arrival_date ? Carbon::parse($eta->arrival_date)->toFormattedDateString() : '';

        $dept = '';
        if ($owner && !empty($owner->Dept_id)) {
            $department = Department::find($owner->Dept_id);
            $dept = $department ? ($department->Dept_name ?? '') : '';
        }
        $position = $owner->designation ?? $owner->AcctName ?? '';
        $destination = $eta->destination ?? '';
        $dateApproved = $eta->approved_at
            ? Carbon::parse($eta->approved_at)->toFormattedDateString()
            : ($eta->updated_at ? $eta->updated_at->toFormattedDateString() : now()->toFormattedDateString());
        $purpose = $eta->purpose ?? '';
        $reason = $eta->purpose_details ?? $eta->purpose ?? '';

        // Resolve department head name and designation
        $deptHeadName = '';
        $department = null;
        if ($owner && !empty($owner->Dept_id)) {
            $department = Department::find($owner->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }

        // Resolve Mayor/Vice Mayor executive signatory
        $settings = Setting::first();
        [$execName, $execDesignation] = $this->resolveExecutiveSignatory($department, $settings);

        // Load Excel template
        $templatePath = storage_path('app/templates/ETA.xlsx');
        if (!file_exists($templatePath)) {
            $etas = collect([$eta]);
            return view('employee.eta-print', compact('etas'))->with('filter', 'single');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Purpose checkbox mapping: purpose => [checkboxCell for copy1, checkboxCell for copy2]
        $purposeCheckboxes = [
            'Audit-Inspection-Licensing'      => ['F10', 'F40'],
            'Client Support'                  => ['K10', 'K40'],
            'Conference'                      => ['M10', 'M40'],
            'Construction Repair Maintenance' => ['B11', 'B41'],
            'Economic Development'            => ['G11', 'G41'],
            'General Expense/Other'           => ['K11', 'K41'],
            'Legal-Law Enforcement'           => ['B12', 'B42'],
            'Legislator'                      => ['F12', 'F42'],
            'Meeting'                         => ['I12', 'I42'],
            'Training'                        => ['K12', 'K42'],
            'Seminar'                         => ['M12', 'M42'],
        ];

        // Fill both copies of the form (rows 1-30 and rows 31-60)
        // --- Copy 1 (top half) ---
        $sheet->setCellValue('D6', $fullName);
        $sheet->setCellValue('D7', $dept);
        $sheet->setCellValue('D8', $position);
        $sheet->setCellValue('D9', $destination);
        $sheet->setCellValue('K6', $departure);
        $sheet->setCellValue('K8', $arrival);
        $sheet->setCellValue('A14', $reason);
        if (isset($purposeCheckboxes[$purpose])) {
            $sheet->setCellValue($purposeCheckboxes[$purpose][0], '✓');
        }
        // Approval section
        if ($eta->status === 'approved') {
            $sheet->setCellValue('D24', '✓');
        }
        if ($deptHeadName) {
            $sheet->setCellValue('A22', $deptHeadName);
        }
        $sheet->setCellValue('J27', $dateApproved);

        // --- Copy 2 (bottom half, +30 row offset) ---
        $sheet->setCellValue('D36', $fullName);
        $sheet->setCellValue('D37', $dept);
        $sheet->setCellValue('D38', $position);
        $sheet->setCellValue('D39', $destination);
        $sheet->setCellValue('K36', $departure);
        $sheet->setCellValue('K38', $arrival);
        $sheet->setCellValue('A44', $reason);
        if (isset($purposeCheckboxes[$purpose])) {
            $sheet->setCellValue($purposeCheckboxes[$purpose][1], '✓');
        }
        // Approval section
        if ($eta->status === 'approved') {
            $sheet->setCellValue('D54', '✓');
        }
        if ($deptHeadName) {
            $sheet->setCellValue('A52', $deptHeadName);
        }
        $sheet->setCellValue('J58', $dateApproved);

        // Apply sheet protection
        $lockApplied = false;
        try {
            $this->protectAllSheets($spreadsheet, $owner);
            $lockApplied = true;
        } catch (\Exception $e) {
            Log::warning('ETA sheet protection failed', [
                'eta_id' => $eta->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Audit log
        $filename = 'ETA-' . $eta->id . '-' . now()->format('Ymd-His') . '.xlsx';

        Log::info('ETA print action (excel)', [
            'eta_id' => $eta->id,
            'printed_by' => $user->id,
            'role' => $user->access_level ?? 'unknown',
            'dept_head_name' => $deptHeadName,
            'executive_signatory' => $execName,
            'lock_applied' => $lockApplied,
            'format_preserved' => true,
            'filename' => $filename,
            'timestamp' => now()->toDateTimeString(),
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $user->id,
            'module' => 'ETA',
            'action' => 'print',
            'target_type' => 'eta',
            'target_id' => $eta->id,
            'details' => [
                'eta_id' => $eta->id,
                'employee_name' => $fullName,
                'role' => $user->access_level ?? 'unknown',
                'dept_head_name' => $deptHeadName,
                'executive_signatory' => $execName,
                'lock_applied' => $lockApplied,
                'filename' => $filename,
            ],
        ]);

        // Stream directly to browser without persisting to disk
        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }

    public function data(Request $request)
    {
        $user = Auth::user();
        $query = Eta::where('user_id', $user->id)->orderBy('departure_date', 'desc');

        $filter = $request->query('filter');
        if ($filter === 'weekly') {
            $query->whereBetween('departure_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($filter === 'monthly') {
            $query->whereMonth('departure_date', now()->month)->whereYear('departure_date', now()->year);
        }

        // resolve department head once for this user
        $deptHeadName = null;
        if ($user && !empty($user->Dept_id)) {
            $department = Department::find($user->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }

        $etas = $query->get()->map(function ($eta) use ($deptHeadName) {
            return [
                'id' => $eta->id,
                'departure_date' => $eta->departure_date,
                'arrival_date' => $eta->arrival_date,
                'destination' => $eta->destination,
                'purpose' => $eta->purpose,
                'purpose_details' => $eta->purpose_details ?? null,
                'dept_head' => $deptHeadName,
                'status' => $eta->status,
                'created_at' => $eta->created_at->toDateTimeString(),
                'can_print' => $eta->status === 'approved',
                'print_url' => route('employee.eta.print.single', ['eta' => $eta->id]),
            ];
        });

        return response()->json(['data' => $etas]);
    }

//    This is cancel
    public function cancel(Request $request, Eta $eta)
    {
        $user = Auth::user();
        if ($eta->user_id !== $user->id) {
            abort(403);
        }

        if ($eta->status !== 'pending') {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Only pending ETAs can be cancelled.'], 400);
            }
            return redirect()->back()->with('error', 'Only pending ETAs can be cancelled.');
        }

        $eta->status = 'cancelled';
        $eta->save();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'ETA cancelled.']);
        }

        return redirect()->route('dashboard.employee.eta')->with('success', 'ETA cancelled.');
    }

    /**
     * Walk the department parent chain to the root department.
     */
    private function resolveRootDepartment(?Department $dept, int $maxDepth = 10): ?Department
    {
        if (!$dept) {
            return null;
        }

        $current = $dept;
        $visited = [];

        while ($current->parent_dept_id && $maxDepth-- > 0) {
            if (in_array($current->parent_dept_id, $visited, true)) {
                break;
            }
            $visited[] = $current->Dept_id;
            $parent = Department::where('Dept_id', $current->parent_dept_id)->first();
            if (!$parent) {
                break;
            }
            $current = $parent;
        }

        return $current;
    }

    /**
     * Check if a department falls under the Vice Mayor's office.
     */
    private function isUnderViceMayor(?Department $dept): bool
    {
        $root = $this->resolveRootDepartment($dept);
        if (!$root) {
            return false;
        }

        $name = strtolower(str_replace(['-', '_'], ' ', trim($root->Dept_name ?? '')));

        return str_contains($name, 'vice mayor') || str_contains($name, 'vice-mayor');
    }

    /**
     * Resolve the executive signatory (Mayor or Vice Mayor) for a department.
     *
     * @return array{0: string, 1: string} [name, designation]
     */
    private function resolveExecutiveSignatory(?Department $dept, ?Setting $settings): array
    {
        if (!$settings) {
            return ['', ''];
        }

        if ($this->isUnderViceMayor($dept)) {
            return [
                $settings->vice_mayor_name ?? '',
                $settings->vice_mayor_designation ?? 'City Vice Mayor',
            ];
        }

        return [
            $settings->mayor_name ?? '',
            $settings->mayor_designation ?? 'City Mayor',
        ];
    }

    /**
     * Lock all sheets in the spreadsheet to prevent editing.
     */
    private function protectAllSheets(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ?User $owner): void
    {
        $first = $owner->first_name ?? ($owner->firstname ?? '');
        $last  = $owner->last_name ?? ($owner->lastname ?? '');
        $password = strtoupper($first . substr((string) $last, 0, 1));

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // NOTE: We intentionally skip bulk getStyle($range)->setLocked() here.
            // All cells are locked by default in Excel when sheet protection is
            // enabled. Calling getStyle() on the entire range forces PhpSpreadsheet
            // to create explicit style objects per cell, which destroys the
            // inherited template formatting (fonts, borders, alignment, colours).
            $protection = $sheet->getProtection();
            $protection->setSheet(true);
            $protection->setPassword($password);
            $protection->setSort(false);
            $protection->setInsertRows(false);
            $protection->setInsertColumns(false);
            $protection->setFormatCells(false);
            $protection->setFormatColumns(false);
            $protection->setFormatRows(false);
            $protection->setDeleteRows(false);
            $protection->setDeleteColumns(false);
            $protection->setAutoFilter(false);
            $protection->setPivotTables(false);
            $protection->setObjects(false);
            $protection->setScenarios(false);
            $protection->setSelectLockedCells(false);
            $protection->setSelectUnlockedCells(false);
        }
    }
}
