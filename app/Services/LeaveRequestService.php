<?php

namespace App\Services;

use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\OicAssignment;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service responsible for leave request related business logic such as
 * permission checks and PDF generation for leave forms.
 */
class LeaveRequestService
{
    protected DepartmentService $departmentService;

    private ?int $cachedLeaveDecimals = null;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    private function leaveDecimals(): int
    {
        return $this->cachedLeaveDecimals ??= (Setting::first()?->leave_balance_decimals ?? 3);
    }

    /**
     * Employees whose VL or SL balance is below 2 days, ordered by last name.
     * Optionally filtered to a single department.
     *
     * @return Collection<int, \stdClass>
     */
    public function criticalBalances(?int $departmentId = null, int $limit = 50): Collection
    {
        return DB::table('leave_balances')
            ->leftJoin('users', 'users.id', '=', 'leave_balances.user_id')
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select(
                'users.id as user_id',
                'users.last_name',
                'users.first_name',
                'departments.Dept_name',
                'leave_balances.VL',
                'leave_balances.SL',
                'leave_balances.id as balance_id',
            )
            ->where(function ($q): void {
                $q->where('leave_balances.VL', '<', 2)
                    ->orWhere('leave_balances.SL', '<', 2);
            })
            ->when($departmentId !== null, fn ($q) => $q->where('users.Dept_id', $departmentId))
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->limit($limit)
            ->get();
    }

    /**
     * Determine if the given user may print the provided leave request.
     */
    public function canPrint(LeaveRequest $leave, User $user): bool
    {
        // do not allow printing for declined or cancelled requests
        if (in_array($leave->status, ['declined', 'cancelled', 'rejected'], true)) {
            return false;
        }

        $role = strtolower(str_replace(['-', '_'], ' ', trim((string) ($user->access_level ?? ''))));

        // Determine if the user is effectively an AO or HR Manager (via actual role or OIC assignment).
        $isAoOrHrm = str_contains($role, 'administrative officer') || str_contains($role, 'hr manager');
        if (! $isAoOrHrm && $leave->status === 'approved') {
            $isAoOrHrm = OicAssignment::where('user_id', $user->id)
                ->active()
                ->whereIn('role', ['administrative officer', 'hr manager'])
                ->exists();
        }

        // Administrative officer and HR manager may print any approved leave - mirrors ETA/locator behaviour.
        if ($leave->status === 'approved' && $isAoOrHrm) {
            return true;
        }

        // Department head (or OIC-as-DH) may print approved leave for employees in their department(s).
        if ($leave->status === 'approved') {
            $isDh = str_contains($role, 'department head');
            if (! $isDh) {
                $isDh = OicAssignment::where('user_id', $user->id)
                    ->active()
                    ->where('role', 'department head')
                    ->exists();
            }
            if ($isDh) {
                $depts = $this->departmentService->resolveAllDepartmentsForUser($user);
                if ($leave->user && $depts->where('Dept_id', $leave->user->Dept_id)->isNotEmpty()) {
                    return true;
                }
            }
        }

        // All other parties require the printing_allowed flag.
        if (empty($leave->printing_allowed)) {
            return false;
        }

        if ($leave->user_id === $user->id) {
            return true;
        }

        $dept = $this->departmentService->resolveDepartmentForUser($user);
        if ($dept && $leave->user && ($leave->user->Dept_id == $dept->Dept_id)) {
            return true;
        }

        return false;
    }

    /**
     * Generate PDF content for a leave request using the existing template mapping.
     * Returns a Symfony response containing PDF bytes on success.
     */
    public function generatePdfResponse(LeaveRequest $leave): Response
    {
        $employee = $leave->user;

        $fullNameParts = [];
        if (! empty($employee->first_name)) {
            $fullNameParts[] = $employee->first_name;
        }
        if (! empty($employee->middle_name)) {
            $fullNameParts[] = $employee->middle_name;
        }
        if (! empty($employee->last_name)) {
            $fullNameParts[] = $employee->last_name;
        }
        if (empty($fullNameParts) && ! empty($employee->name)) {
            $fullNameParts[] = $employee->name;
        }
        $fullName = implode(' ', $fullNameParts);

        $start = $leave->start_date ? Carbon::parse($leave->start_date)->format('M d, Y') : '';
        $end = $leave->end_date ? Carbon::parse($leave->end_date)->format('M d, Y') : '';
        $approvedAt = $leave->updated_at ? $leave->updated_at->format('M d, Y') : '';

        $departmentName = 'N/A';
        $applicant = null;
        if ($leave->user_id) {
            $applicant = User::find($leave->user_id);
        }
        if ($applicant && isset($applicant->Dept_id) && $applicant->Dept_id) {
            $dept = Department::where('Dept_id', $applicant->Dept_id)->first();
            if ($dept && isset($dept->Dept_name)) {
                $departmentName = $dept->Dept_name;
            }
        }

        $templatePath = storage_path('app/templates/Leave_Form.pdf');
        if (! file_exists($templatePath)) {
            // fallback: render existing blade fallback view
            $view = view('employee.leave-print', ['leaves' => collect([$leave])]);

            return response($view->render(), 200);
        }

        $mappingFile = storage_path('app/templates/leave_mapping.php');
        $mapping = [];
        if (file_exists($mappingFile)) {
            $mapping = include $mappingFile;
        }

        $pdf = new Fpdi;
        $pdf->setSourceFile($templatePath);
        $tplId = $pdf->importPage(1);
        $pdf->AddPage();
        $pdf->useTemplate($tplId);

        $write = function ($key, $text, $style = ['font' => 'Arial', 'size' => 9, 'bold' => false]) use ($pdf, $mapping) {
            $cfg = $mapping[$key] ?? null;
            $font = $cfg['font'] ?? $style['font'] ?? 'Arial';
            $size = $cfg['size'] ?? $style['size'] ?? 9;
            $isBold = $cfg['bold'] ?? $style['bold'] ?? false;
            $pdf->SetFont($font, $isBold ? 'B' : '', $size);

            if ($cfg && isset($cfg['x']) && isset($cfg['y']) && is_numeric($cfg['x']) && is_numeric($cfg['y'])) {
                $pdf->SetXY($cfg['x'], $cfg['y']);
                $pdf->Write(5, $text);
            }
        };

        $put = function ($key, $text) use ($pdf, $mapping) {
            $cfg = $mapping[$key] ?? null;
            if ($cfg && isset($cfg['x']) && isset($cfg['y']) && is_numeric($cfg['x']) && is_numeric($cfg['y'])) {
                $font = $cfg['font'] ?? 'Arial';
                $size = $cfg['size'] ?? 9;
                $pdf->SetFont($font, '', $size);
                $pdf->SetXY($cfg['x'], $cfg['y']);
                $pdf->Write(5, (string) $text);

                return true;
            }

            return false;
        };

        $write('full_name', $fullName, ['bold' => true]);
        $write('department', $departmentName, ['bold' => true]);

        // Department head name
        $deptHeadName = '';
        try {
            if (isset($dept) && $dept && ! empty($dept->EmpNo)) {
                $headUser = User::where('EmpNo', $dept->EmpNo)->first();
                if ($headUser) {
                    $parts = [];
                    if (! empty($headUser->first_name)) {
                        $parts[] = $headUser->first_name;
                    }
                    if (! empty($headUser->middle_name)) {
                        $parts[] = $headUser->middle_name;
                    }
                    if (! empty($headUser->last_name)) {
                        $parts[] = $headUser->last_name;
                    }
                    if (empty($parts) && ! empty($headUser->name)) {
                        $parts[] = $headUser->name;
                    }
                    $deptHeadName = implode(' ', $parts);
                }
            }
        } catch (\Exception $e) {
            $deptHeadName = '';
        }
        if ($deptHeadName !== '') {
            $write('department_head', $deptHeadName);
        }
        $write('period', ($start && $end) ? "{$start} to {$end}" : '');
        $write('total_days', (string) ($leave->total_days ?? ''));
        $write('approved_at', $approvedAt, ['bold' => true]);
        $write('vl', (string) ($leave->balance_vacation_leave ?? ''));
        $write('sl', (string) ($leave->balance_sick_leave ?? ''));

        $put('abroad_place', $leave->details_location_specify ?? '');

        // sick treatment handling and other mapping adjustments follow same logic
        $sickTreatment = strtolower(trim((string) ($leave->details_sick_treatment ?? '')));
        $specCoords = $mapping['specify_illness_coords'] ?? null;
        if ($sickTreatment !== '') {
            if (strpos($sickTreatment, 'in hospital') !== false || strpos($sickTreatment, 'in_hospital') !== false || strpos($sickTreatment, 'hospital') !== false) {
                if (is_array($specCoords) && isset($specCoords['in_hospital']) && is_array($specCoords['in_hospital'])) {
                    $c = $specCoords['in_hospital'];
                    $mapping['specify_illness'] = ['x' => $c[0], 'y' => $c[1], 'font' => 'Arial', 'size' => 9];
                } else {
                    $mapping['specify_illness'] = ['x' => 147, 'y' => 94, 'font' => 'Arial', 'size' => 9];
                }
            } elseif (strpos($sickTreatment, 'out') !== false || strpos($sickTreatment, 'out patient') !== false || strpos($sickTreatment, 'outpatient') !== false) {
                if (is_array($specCoords) && isset($specCoords['out_patient']) && is_array($specCoords['out_patient'])) {
                    $c = $specCoords['out_patient'];
                    $mapping['specify_illness'] = ['x' => $c[0], 'y' => $c[1], 'font' => 'Arial', 'size' => 9];
                } else {
                    $mapping['specify_illness'] = ['x' => 149, 'y' => 99, 'font' => 'Arial', 'size' => 9];
                }
            }
        }

        $put('specify_illness', $leave->details_sick_illness ?? '');

        $purpose = (string) ($leave->reason ?? '');

        // Compute printable reason, with Wellness override when WLNS is present in preview or leave type
        $reason = (string) ($leave->reason ?? '');
        $isWellnessPreview = false;
        if (! empty($preview)) {
            if (isset($preview['WLNS']) && floatval($preview['WLNS']) > 0) {
                $isWellnessPreview = true;
            }
        }
        if ($isWellnessPreview || stripos((string) ($leave->leave_type ?? ''), 'wellness') !== false || stripos((string) ($leave->leave_type ?? ''), 'wlns') !== false) {
            $reason = 'Wellness';
        }

        // Section 7.A ("Total Earned") reflects the balance AS OF WHEN THIS LEAVE WAS
        // FILED, not whatever the employee's balance happens to be today - otherwise
        // every approved leave printed after the fact would show today's live balance
        // and look identical regardless of which specific request is being printed.
        // leave_requests.balance_* is a snapshot taken at filing time (before this
        // leave's own deduction); the live balance is only a fallback for older rows
        // filed before that snapshot existed, or for CTO, which has no snapshot column.
        $empLB = $employee->leaveBalance ?? null;
        $displayTotalEarnedVL = $leave->balance_vacation_leave ?? ($empLB->VL ?? 0);
        $displayTotalEarnedSL = $leave->balance_sick_leave ?? ($empLB->SL ?? 0);
        $displayTotalEarnedWLNS = $leave->balance_wellness_leave ?? ($empLB->WLNS ?? 0);
        $displayTotalEarnedSPL = $leave->balance_special_leave_privilege ?? ($empLB->SPL ?? 0);
        $displayTotalEarnedCTO = $empLB->CTO ?? 0;
        $displayTotalEarnedSP = $leave->balance_solo_parent_leave ?? ($empLB->SP ?? 0);

        // Prefer per-type preview if available
        $preview = [];
        if (! empty($leave->printing_deduction_details)) {
            try {
                $preview = json_decode($leave->printing_deduction_details, true) ?: [];
            } catch (\Exception $e) {
                $preview = [];
            }
        }
        $lt = strtolower((string) ($leave->leave_type ?? ''));
        $displayRequestedVL = isset($preview['VL']) ? floatval($preview['VL']) : ((stripos($lt, 'vacation') !== false || stripos($lt, 'vl') !== false) ? ($leave->paid_days ?? 0) : 0);
        $displayRequestedSL = isset($preview['SL']) ? floatval($preview['SL']) : ((stripos($lt, 'sick') !== false || stripos($lt, 'sl') !== false) ? ($leave->paid_days ?? 0) : 0);

        $displayRequestedWLNS = isset($preview['WLNS']) ? floatval($preview['WLNS']) : 0;
        $displayRequestedSPL = isset($preview['SPL']) ? floatval($preview['SPL']) : 0;
        $displayRequestedCTO = isset($preview['CTO']) ? floatval($preview['CTO']) : 0;
        $displayRequestedSP = isset($preview['SP']) ? floatval($preview['SP']) : 0;

        // VL/SL/WLNS/SPL/SP above already come from the filing-time snapshot (pre-deduction),
        // so no adjustment is needed there. CTO has no snapshot column and still reads the
        // live (post-deduction) balance, so once its deduction has actually been applied,
        // add it back to show the same pre-deduction total Section 7.A showed at filing time.
        if (! empty($leave->printing_deduction_applied)) {
            $displayTotalEarnedCTO += $displayRequestedCTO;
        }

        $displayBalanceVL = $displayTotalEarnedVL - $displayRequestedVL;
        $displayBalanceSL = $displayTotalEarnedSL - $displayRequestedSL;

        // Also compute combined totals for Section 7.A (Total Earned, Less This Application, Balance)
        $combinedTotalEarned = $displayTotalEarnedVL + $displayTotalEarnedSL + $displayTotalEarnedWLNS + $displayTotalEarnedSPL + $displayTotalEarnedCTO + $displayTotalEarnedSP;
        $combinedLess = $displayRequestedVL + $displayRequestedSL + $displayRequestedWLNS + $displayRequestedSPL + $displayRequestedCTO + $displayRequestedSP;
        $combinedBalance = $combinedTotalEarned - $combinedLess;

        Log::info('Leave print values computed', [
            'leave_id' => $leave->id,
            'printing_allowed' => (bool) ($leave->printing_allowed ?? false),
            'leave_status' => $leave->status,
            'vl_total_earned' => $displayTotalEarnedVL,
            'vl_requested' => $displayRequestedVL,
            'vl_balance' => $displayBalanceVL,
            'sl_total_earned' => $displayTotalEarnedSL,
            'sl_requested' => $displayRequestedSL,
            'sl_balance' => $displayBalanceSL,
            'wlns_total_earned' => $displayTotalEarnedWLNS,
            'wlns_requested' => $displayRequestedWLNS,
            'spl_total_earned' => $displayTotalEarnedSPL,
            'spl_requested' => $displayRequestedSPL,
            'cto_total_earned' => $displayTotalEarnedCTO,
            'cto_requested' => $displayRequestedCTO,
            'sp_total_earned' => $displayTotalEarnedSP,
            'sp_requested' => $displayRequestedSP,
            'combined_total_earned' => $combinedTotalEarned,
            'combined_less' => $combinedLess,
            'combined_balance' => $combinedBalance,
        ]);

        // Attempt to write combined totals into mapping keys for Section 7.A if mapping contains them
        $dec = $this->leaveDecimals();
        $write('total_earned', number_format($combinedTotalEarned, $dec, '.', ''));
        $write('less_this_application', number_format($combinedLess, $dec, '.', ''));
        $write('balance_total', number_format($combinedBalance, $dec, '.', ''));

        // write reason field into PDF mapping if present
        try {
            $put('reason', $reason);
        } catch (\Throwable $_) {
            // ignore if mapping doesn't include reason
        }

        $PaidDays = $leave->paid_days ?? 0;
        $LWOPDays = $leave->lwop_days ?? 0;

        $leaveDecimals = $this->leaveDecimals();
        $formatUpTo3 = function ($val) use ($leaveDecimals) {
            if (! is_numeric($val)) {
                return (string) $val;
            }
            $s = (string) $val;
            $neg = false;
            if (substr($s, 0, 1) === '-') {
                $neg = true;
                $s = substr($s, 1);
            }
            if (strpos($s, '.') === false) {
                return $neg ? ('-'.$s) : $s;
            }
            [$int, $dec] = explode('.', $s, 2);
            $dec = substr($dec.str_repeat('0', $leaveDecimals), 0, $leaveDecimals);
            $dec = rtrim($dec, '0');
            if ($dec === '') {
                return $neg ? ('-'.$int) : $int;
            }

            return ($neg ? '-' : '').$int.'.'.$dec;
        };

        $m = $mapping;

        if (! $put('vl_total_earned', $formatUpTo3($displayTotalEarnedVL))) {
            $pdf->SetXY(60, 204);
            $pdf->Write(5, $formatUpTo3($displayTotalEarnedVL));
        }
        if (! $put('vl_requested', $formatUpTo3($displayRequestedVL))) {
            $pdf->SetXY(60, 208);
            $pdf->Write(5, $formatUpTo3($displayRequestedVL));
        }
        if (! $put('vl_balance', $formatUpTo3($displayBalanceVL))) {
            $pdf->SetXY(60, 212);
            $pdf->Write(5, $formatUpTo3($displayBalanceVL));
        }

        if (! $put('sl_total_earned', $formatUpTo3($displayTotalEarnedSL))) {
            $pdf->SetXY(87, 204);
            $pdf->Write(5, $formatUpTo3($displayTotalEarnedSL));
        }
        if (! $put('sl_requested', $formatUpTo3($displayRequestedSL))) {
            $pdf->SetXY(87, 208);
            $pdf->Write(5, $formatUpTo3($displayRequestedSL));
        }
        if (! $put('sl_balance', $formatUpTo3($displayBalanceSL))) {
            $pdf->SetXY(87, 212);
            $pdf->Write(5, $formatUpTo3($displayBalanceSL));
        }

        if (! $put('paid_days', $formatUpTo3($PaidDays))) {
            $pdf->SetXY(23, 236);
            $pdf->Write(5, $formatUpTo3($PaidDays));
        }
        if (! $put('lwop_days', $formatUpTo3($LWOPDays))) {
            $pdf->SetXY(23, 239);
            $pdf->Write(5, $formatUpTo3($LWOPDays));
        }

        $selectedKeys = array_filter(array_map('trim', explode(',', (string) $leave->leave_type)));
        $leaveTypeCoords = $m['leave_type_coords'] ?? [];
        $normCoords = [];
        foreach ($leaveTypeCoords as $k => $v) {
            $normCoords[strtolower(trim($k))] = $v;
        }
        foreach ($selectedKeys as $key) {
            $normKey = strtolower(trim($key));
            if (! isset($normCoords[$normKey])) {
                continue;
            }
            [$lx, $ly] = $normCoords[$normKey];
            $pdf->SetXY($lx, $ly);
            if ($normKey === 'others') {
                $otherLabel = $leave->LeaveTypeName ?? ($leave->leave_type ?? 'Others');
                $pdf->SetFont('Arial', '', 9);
                $area = $m['others_area'] ?? null;
                if ($area) {
                    $pdf->SetXY($area['x'], $area['y']);
                    $pdf->MultiCell($area['w'], $area['h'], $otherLabel);
                } else {
                    $pdf->MultiCell(120, 5, $otherLabel);
                }
            } elseif ($normKey === 'wellness leave' || $normKey === 'wellness' || $normKey === 'wlns') {

            } else {
                $pdf->Write(5, 'X');
            }
        }

        $purposeText = $purpose;
        $purposeLower = strtolower($purposeText);

        $markX = function ($x, $y) use ($pdf) {
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetXY($x, $y);
            $pdf->Write(5, 'X');
        };

        $pm = $m['purpose_marks'] ?? [];
        if (strpos($purposeLower, 'within the philippines') !== false) {
            $mark = $pm['within_the_philippines'] ?? [115, 81];
            $markX($mark[0], $mark[1]);
        }
        if (strpos($purposeLower, 'abroad') !== false) {
            $mark = $pm['abroad'] ?? [115, 86];
            $markX($mark[0], $mark[1]);
        }
        if (strpos($purposeLower, 'in hospital') !== false) {
            $mark = $pm['in_hospital'] ?? [115, 96];
            $markX($mark[0], $mark[1]);
        }
        if (strpos($purposeLower, 'out patient') !== false || strpos($purposeLower, 'outpatient') !== false) {
            $mark = $pm['out_patient'] ?? [115, 101];
            $markX($mark[0], $mark[1]);
        }

        $treatment = strtolower(trim((string) ($leave->details_sick_treatment ?? '')));
        if ($treatment !== '') {
            if (strpos($treatment, 'hospital') !== false || $treatment === 'in_hospital' || $treatment === 'in-hospital' || $treatment === 'hospital') {
                $coords = $pm['in_hospital'] ?? [115, 96];
                $markX($coords[0], $coords[1]);
            } elseif (strpos($treatment, 'out') !== false || $treatment === 'out_patient' || $treatment === 'outpatient') {
                $coords = $pm['out_patient'] ?? [115, 101];
                $markX($coords[0], $coords[1]);
            }
        }

        if (preg_match('/special\s+leave\s+benefits\s+for\s+women\s*:\s*([^|]+)/i', $purposeText, $wm)) {
            $womenIllness = trim((string) $wm[1]);
            if ($womenIllness !== '') {
                $pdf->SetFont('Arial', '', 9);
                $coords = $pm['women_illness'] ?? [115, 122];
                $pdf->SetXY($coords[0], $coords[1]);
                $pdf->MultiCell(80, 4, $womenIllness);
            }
        }

        if (strpos($purposeLower, "completion of master's degree") !== false || strpos($purposeLower, 'completion of masters degree') !== false) {
            $coords = $pm['study_completion'] ?? [115, 132];
            $markX($coords[0], $coords[1]);
        }
        if (strpos($purposeLower, 'bar/board examination review') !== false || strpos($purposeLower, 'bar') !== false) {
            $coords = $pm['bar_review'] ?? [115, 137];
            $markX($coords[0], $coords[1]);
        }

        if (strpos($purposeLower, 'monetization of leave credits') !== false || strpos($purposeLower, 'monetization') !== false) {
            $coords = $pm['monetization'] ?? [115, 148];
            $markX($coords[0], $coords[1]);
        }
        if (strpos($purposeLower, 'terminal leave') !== false) {
            $coords = $pm['terminal_leave'] ?? [115, 152];
            $markX($coords[0], $coords[1]);
        }

        $isExplicitOthers = stripos((string) $leave->leave_type, 'Others') !== false;
        if ($isExplicitOthers && in_array('Others', $selectedKeys)) {
            $area = $m['others_area'] ?? null;
            $reason = (string) ($leave->reason ?? '');
            $pdf->SetFont('Arial', '', 11);
            if ($area) {
                $pdf->SetXY($area['x'], $area['y']);
                $pdf->MultiCell($area['w'], $area['h'], $reason);
            } else {
                $pdf->SetXY(23, 152);
                $pdf->MultiCell(120, 5, $reason);
            }
        }

        // --- Signatory logic ---
        // Resolve executive signatory via recursive department hierarchy
        $siteSettings = Setting::first();
        [$signatoryName, $signatoryDesignation] = $this->resolveExecutiveSignatory(
            isset($dept) ? $dept : null,
            $siteSettings
        );

        if ($siteSettings) {
            if ($signatoryName !== '') {
                $write('signatory_name', $signatoryName);
            }
            if ($signatoryDesignation !== '') {
                $write('signatory_designation', $signatoryDesignation);
            }

            // HR Manager is always written
            if (! empty($siteSettings->hr_manager_name)) {
                $write('hr_manager_name', $siteSettings->hr_manager_name);
            }
            if (! empty($siteSettings->hr_manager_designation)) {
                $write('hr_manager_designation', $siteSettings->hr_manager_designation);
            }
        }

        $pageCount = $pdf->setSourceFile($templatePath);
        if ($pageCount > 1) {
            for ($pageNo = 2; $pageNo <= $pageCount; $pageNo++) {
                $tpl = $pdf->importPage($pageNo);
                $pdf->AddPage();
                $pdf->useTemplate($tpl, 0, 0, 210);
            }
        }

        $pdfContent = $pdf->Output('S');

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="leave-'.$leave->id.'.pdf"');
    }

    /**
     * Approve a leave request and perform balance deductions where applicable.
     * Keeps the same response shapes as the controller version.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return RedirectResponse|JsonResponse
     */
    public function approveLeave($request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);
        // Enforce that Department Head / Administrative Officer must allow printing first
        $actor = auth()->user();
        $actorRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($actor->access_level ?? ''))));
        if (in_array($actorRole, ['department head', 'administrative officer'], true) && empty($leave->printing_allowed)) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Printing must be allowed before approval.'], 422);
            }

            return redirect()->back()->with('error', 'Printing must be allowed before approval.');
        }

        if ($leave->status === 'approved') {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Leave already approved.']);
            }

            return redirect()->back()->with('success', 'Leave already approved.');
        }

        // If this is a reschedule leave, atomically cancel the ORIGINAL DATES that were
        // specifically linked to this reschedule (not necessarily the whole original
        // request — a partial reschedule links only the dates being replaced) and
        // restore balances for just those dates.
        if (! empty($leave->rescheduled_from_id)) {
            $original = LeaveRequest::with(['user', 'leaveDates'])->find($leave->rescheduled_from_id);
            if ($original && $original->status === 'approved') {
                try {
                    DB::transaction(function () use ($original, $leave) {
                        $lb = $original->user->leaveBalance ?? null;
                        $aggregateService = app(LeaveDateAggregateService::class);

                        $linkedDates = LeaveDate::where('rescheduled_to_leave_request_id', $leave->id)->get();
                        $activeDates = $original->leaveDates()->where('is_cancelled', false)->get();
                        // Legacy safety net: a reschedule created before this link existed has no
                        // linked dates — fall back to the old whole-request behavior.
                        $datesToCancel = $linkedDates->isNotEmpty() ? $linkedDates : $activeDates;
                        $isWholeRequest = $datesToCancel->count() === $activeDates->count();

                        $applied = [];
                        if ($isWholeRequest && ! empty($original->printing_deduction_details)) {
                            $applied = json_decode($original->printing_deduction_details, true) ?: [];
                        }

                        $candidates = [
                            'VL' => ['balance_vacation_leave', 'vl', 'VL'],
                            'SL' => ['balance_sick_leave', 'sl', 'SL'],
                            'WLNS' => ['balance_wellness_leave', 'wlns', 'WLNS'],
                            'SPL' => ['balance_special_leave_privilege', 'spl', 'SPL'],
                            'CTO' => ['balance_cto', 'cto', 'CTO'],
                            'SP' => ['balance_solo_parent_leave', 'sp', 'SP'],
                        ];

                        $restored = [];
                        if (! empty($applied) && $lb) {
                            foreach ($applied as $type => $amt) {
                                if (! is_numeric($amt) || floatval($amt) <= 0) {
                                    continue;
                                }
                                $key = strtoupper((string) $type);
                                $found = null;
                                foreach (($candidates[$key] ?? []) as $cand) {
                                    if (array_key_exists($cand, $lb->getAttributes()) || isset($lb->{$cand})) {
                                        $found = $cand;
                                        break;
                                    }
                                }
                                if ($found) {
                                    $lb->{$found} = floatval($lb->{$found} ?? 0) + floatval($amt);
                                    $restored[$key] = floatval($amt);
                                }
                            }
                            $lb->save();
                        } elseif ($lb) {
                            // Fallback: restore from the linked leave_dates only, gated by is_lwop
                            // so an LWOP date (which never drew balance) isn't over-refunded.
                            $restored = $aggregateService->refundDates($datesToCancel, $lb);
                            $lb->save();
                        }

                        // Transfer the "Total Earned" snapshot (read by the printed form) from
                        // the original to this new leave, instead of reading the live balance.
                        // Reading live balance made every reschedule off the same original
                        // independently show "current + 1", so two unrelated reschedule targets
                        // off the same original printed identical numbers. Instead, each
                        // departing date's credit is handed off explicitly: the new leave
                        // inherits whatever the original's snapshot was immediately before this
                        // transfer, and the original's own snapshot shrinks by the same amount -
                        // producing a proper decreasing chain across prints (55 -> 54 -> 53 -> 52)
                        // instead of two prints landing on the same number. CTO has no snapshot
                        // column on leave_requests, so it's left out and keeps reading live.
                        $snapshotColumns = [
                            'VL' => 'balance_vacation_leave',
                            'SL' => 'balance_sick_leave',
                            'WLNS' => 'balance_wellness_leave',
                            'SPL' => 'balance_special_leave_privilege',
                            'SP' => 'balance_solo_parent_leave',
                        ];
                        foreach ($restored as $key => $amt) {
                            $column = $snapshotColumns[$key] ?? null;
                            if (! $column || $amt <= 0) {
                                continue;
                            }
                            $priorSnapshot = $original->{$column} ?? 0;
                            $leave->{$column} = $priorSnapshot;
                            $original->{$column} = $priorSnapshot - $amt;
                        }
                        $leave->save();

                        foreach ($datesToCancel as $ld) {
                            $ld->is_cancelled = true;
                            $ld->cancel_reason = 'Rescheduled';
                            $ld->cancelled_by = auth()->id();
                            $ld->cancelled_at = now();
                            $ld->save();
                        }

                        $aggregateService->recomputeParentAfterDateChange($original, 'Rescheduled');
                        // recomputeParentAfterDateChange already saved $original above; only the
                        // reschedule single-flight gate is left to reconcile here. A whole-request
                        // reschedule permanently marks it 'Rescheduled' (matches the old terminal
                        // state); a partial reschedule clears the gate so the untouched remaining
                        // dates can be cancelled/rescheduled again later.
                        $original->reschedule_status = $original->status === 'cancelled' ? 'Rescheduled' : null;
                        $original->save();

                        try {
                            HRAuditTrail::create([
                                'actor_user_id' => auth()->id(),
                                'module' => 'leave',
                                'action' => 'cancel_restore_balances',
                                'target_type' => 'leave_request',
                                'target_id' => $original->id,
                                'details' => [
                                    'restored' => $restored,
                                    'reason' => 'Rescheduled to leave #'.$original->rescheduledLeaves()->latest('id')->first()?->id,
                                    'cancelled_at' => now()->toDateTimeString(),
                                ],
                            ]);
                        } catch (\Exception $e) {
                            Log::error('HRAuditTrail write failed on reschedule cancel', ['leave_id' => $original->id, 'error' => $e->getMessage()]);
                        }

                        try {
                            app(LeaveLedgerService::class)->writeLedgerEntry([
                                'user_id' => $original->user_id,
                                'transaction_date' => $datesToCancel->min('leave_date') ?? now()->toDateString(),
                                'period_end_date' => $datesToCancel->max('leave_date'),
                                'transaction_type' => 'LEAVE_CANCELLED',
                                'leave_type' => ! empty($restored) ? implode('+', array_keys($restored)) : 'VL',
                                'credit_vl' => floatval($restored['VL'] ?? 0),
                                'credit_sl' => floatval($restored['SL'] ?? 0),
                                'debit_vl' => 0,
                                'debit_sl' => 0,
                                'reference_id' => $original->id,
                                'reference_type' => 'leave_request',
                                'created_by' => auth()->id(),
                                'is_system' => false,
                                'remarks' => 'Leave rescheduled',
                            ]);
                        } catch (\Throwable $ex) {
                            Log::error('LeaveLedger write failed on reschedule cancel', ['leave_id' => $original->id, 'error' => $ex->getMessage()]);
                        }
                    });
                } catch (\Exception $e) {
                    Log::error('Failed to cancel original leave during reschedule approval', ['original_id' => $original->id, 'error' => $e->getMessage()]);
                    if ($request->ajax() || $request->wantsJson()) {
                        return response()->json(['success' => false, 'message' => 'Failed to cancel original leave: '.$e->getMessage()], 422);
                    }

                    return redirect()->back()->with('error', 'Failed to cancel original leave: '.$e->getMessage());
                }

                // Notify all 4 parties about the reschedule approval (best-effort)
                try {
                    $employee = $leave->user;
                    $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');
                    $department = $employee->Dept_id ? Department::find($employee->Dept_id) : null;
                    $departmentName = $department?->Dept_name ?? 'N/A';

                    $filerRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
                    $dh = null;
                    $ao = null;
                    if (in_array($filerRole, ['department head', 'hr manager'])) {
                        $dh = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'mayor'")->first();
                    } elseif ($department) {
                        $dh = $this->departmentService->getDepartmentHeadUser($department);
                        $ao = $this->departmentService->getAdminOfficerUser($department);
                    }
                    $lm = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'leave manager'")->first();

                    $notifDetails = [
                        'Employee' => $empName,
                        'Department' => $departmentName,
                        'Leave Type' => $leave->leave_type ?? 'N/A',
                        'New Start Date' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                        'New End Date' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                        'Original Leave' => '#'.$original->id.' ('.Carbon::parse($original->start_date)->format('M j').' – '.Carbon::parse($original->end_date)->format('M j, Y').')',
                        'Approved By' => auth()->user()?->name ?? 'Approver',
                    ];

                    $employee->notify(new HrisTransactionNotification(
                        requestType: 'Leave Reschedule',
                        status: 'Approved',
                        details: $notifDetails,
                        actor: auth()->user()?->name ?? 'Approver',
                    ));

                    foreach (array_filter([$dh, $ao, $lm]) as $recipient) {
                        if ($recipient->id !== $employee->id) {
                            $recipient->notify(new HrisTransactionNotification(
                                requestType: 'Leave Reschedule',
                                status: 'Approved',
                                details: $notifDetails,
                                actor: auth()->user()?->name ?? 'Approver',
                            ));
                        }
                    }
                } catch (\Exception $ex) {
                    Log::error('Reschedule approval notification failed', ['leave_id' => $leave->id, 'error' => $ex->getMessage()]);
                }
            }
        }

        $user = $leave->user;
        $leaveBalance = $user->leaveBalance;
        if (! $leaveBalance) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'No leave balance record found for this user.'], 422);
            }

            return redirect()->back()->with('error', 'No leave balance record found for this user.');
        }

        $column = null;
        $label = strtolower($leave->leave_type ?? '');
        if (str_contains($label, 'vacation') || str_contains($label, 'vl') || str_contains($label, 'mandatory') || str_contains($label, 'forced') || str_contains($label, 'others')) {
            // "Others" (e.g. Mourning Leave) is deducted from Vacation Leave credits, same as Vacation Leave itself
            $column = 'VL';
        } elseif (str_contains($label, 'sick') || str_contains($label, 'sl')) {
            $column = 'SL';
        } elseif (str_contains($label, 'wellness') || str_contains($label, 'wlns')) {
            $column = 'WLNS';
        } elseif (str_contains($label, 'solo') || str_contains($label, 'solo parent')) {
            $column = 'SP';
        } elseif (str_contains($label, 'special') || str_contains($label, 'privilege') || str_contains($label, 'spl')) {
            $column = 'SPL';
        } elseif (str_contains($label, 'cto')) {
            $column = 'CTO';
        }

        if (! $column) {
            $parts = array_map('trim', explode(',', $leave->leave_type));
            foreach ($parts as $p) {
                $pl = strtolower($p);
                if (str_contains($pl, 'vacation') || str_contains($pl, 'vl') || str_contains($pl, 'mandatory') || str_contains($pl, 'forced') || str_contains($pl, 'others')) {
                    $column = 'VL';
                    break;
                }
                if (str_contains($pl, 'sick') || str_contains($pl, 'sl')) {
                    $column = 'SL';
                    break;
                }
                if (str_contains($pl, 'wellness') || str_contains($pl, 'wlns')) {
                    $column = 'WLNS';
                    break;
                }
                if (str_contains($pl, 'solo') || str_contains($pl, 'solo parent')) {
                    $column = 'SP';
                    break;
                }
                if (str_contains($pl, 'special') || str_contains($pl, 'privilege') || str_contains($pl, 'spl')) {
                    $column = 'SPL';
                    break;
                }
                if (str_contains($pl, 'cto')) {
                    $column = 'CTO';
                    break;
                }
            }
        }

        $toDeduct = floatval($leave->paid_days ?? 0);
        if ($toDeduct <= 0) {
            $leave->status = 'approved';
            $leave->save();
            $this->notifyLeaveApproval($leave, $leaveBalance);
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Leave approved.']);
            }

            return redirect()->back()->with('success', 'Leave approved.');
        }

        if (! $column) {
            $leave->status = 'approved';
            $leave->save();
            $this->notifyLeaveApproval($leave, $leaveBalance);
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Leave approved.']);
            }

            return redirect()->back()->with('success', 'Leave approved.');
        }

        $deductionLog = [];
        // capture original leave balances for audit
        $originalBalances = [
            'VL' => $leaveBalance->VL ?? 0,
            'SL' => $leaveBalance->SL ?? 0,
            'WLNS' => $leaveBalance->WLNS ?? 0,
            'SP' => $leaveBalance->SP ?? 0,
            'SPL' => $leaveBalance->SPL ?? 0,
            'CTO' => $leaveBalance->CTO ?? 0,
        ];
        try {
            // If a per-type preview exists, apply those deductions exactly.
            $preview = [];
            if (! empty($leave->printing_deduction_details)) {
                try {
                    $preview = json_decode($leave->printing_deduction_details, true) ?: [];
                } catch (\Exception $_) {
                    $preview = [];
                }
            }

            // helper to resolve field name on leaveBalance
            // tries preferred DB-style column names first (balance_*), then lowercase/uppercase short codes
            // Defined here (not inside the branch below) so both the preview-based and
            // fallback single-column deduction branches can use it.
            $resolveField = function ($leaveBalance, $key) {
                $map = [
                    'VL' => ['balance_vacation_leave', 'vl', 'VL'],
                    'SL' => ['balance_sick_leave', 'sl', 'SL'],
                    'WLNS' => ['balance_wellness_leave', 'wlns', 'WLNS'],
                    'SPL' => ['balance_special_leave_privilege', 'spl', 'SPL'],
                    'CTO' => ['balance_cto', 'cto', 'CTO'],
                    'SP' => ['balance_solo_parent_leave', 'sp', 'SP'],
                ];
                $candidates = $map[$key] ?? [strtolower($key), strtoupper($key)];
                foreach ($candidates as $cand) {
                    if (array_key_exists($cand, $leaveBalance->getAttributes()) || isset($leaveBalance->{$cand})) {
                        return $cand;
                    }
                }

                return null;
            };

            if (! empty($preview)) {
                DB::transaction(function () use ($leaveBalance, $preview, $leave, &$deductionLog, $resolveField) {
                    foreach ($preview as $col => $amt) {
                        if (! is_numeric($amt) || floatval($amt) <= 0) {
                            continue;
                        }
                        $amt = floatval($amt);
                        $key = strtoupper((string) $col);
                        $field = $resolveField($leaveBalance, $key);
                        if (! $field) {
                            // unknown or non-deductible type; skip
                            continue;
                        }
                        if (floatval($leaveBalance->{$field} ?? 0) < $amt) {
                            throw new \Exception('Insufficient '.$key.' balance.');
                        }
                        $leaveBalance->{$field} = floatval($leaveBalance->{$field} ?? 0) - $amt;
                        $deductionLog[$key] = $amt;
                    }
                    $leaveBalance->save();

                    $leave->status = 'approved';

                    if (\Schema::hasColumn('leave_requests', 'printing_deduction_applied')) {
                        $leave->printing_deduction_applied = true;
                    }
                    if (\Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
                        $leave->printing_deduction_details = json_encode($deductionLog);
                    }

                    // persist only metadata; do NOT update leave_requests balance snapshot fields here
                    $leave->save();
                });
            } else {
                // Fallback: previous single-column deduction behavior
                DB::transaction(function () use ($leaveBalance, $column, $toDeduct, $leave, &$deductionLog, $resolveField) {
                    $colKey = strtoupper((string) $column);

                    if ($colKey === 'SL') {
                        $slField = $resolveField($leaveBalance, 'SL');
                        $vlField = $resolveField($leaveBalance, 'VL');
                        $dedFromSL = min(floatval($leaveBalance->{$slField} ?? 0), $toDeduct);
                        if ($slField) {
                            $leaveBalance->{$slField} = floatval($leaveBalance->{$slField} ?? 0) - $dedFromSL;
                        }
                        $deductionLog['SL'] = $dedFromSL;
                        $remaining = $toDeduct - $dedFromSL;
                        if ($remaining > 0) {
                            $dedFromVL = min(floatval($leaveBalance->{$vlField} ?? 0), $remaining);
                            if ($vlField) {
                                $leaveBalance->{$vlField} = floatval($leaveBalance->{$vlField} ?? 0) - $dedFromVL;
                            }
                            $deductionLog['VL'] = $dedFromVL;
                            $remaining -= $dedFromVL;
                        }
                        if (isset($remaining) && $remaining > 0) {
                            throw new \Exception('Insufficient combined SL/VL balance.');
                        }
                    } elseif ($colKey === 'WLNS') {
                        $fld = $resolveField($leaveBalance, 'WLNS');
                        if (floatval($leaveBalance->{$fld} ?? 0) < $toDeduct) {
                            throw new \Exception('Insufficient wellness leave (WLNS) balance.');
                        }
                        $leaveBalance->{$fld} = floatval($leaveBalance->{$fld} ?? 0) - $toDeduct;
                        $deductionLog['WLNS'] = $toDeduct;
                    } else {
                        $fld = $resolveField($leaveBalance, $colKey);
                        if (! $fld) {
                            throw new \Exception('Unsupported leave balance column: '.$column);
                        }
                        if (floatval($leaveBalance->{$fld} ?? 0) < $toDeduct) {
                            throw new \Exception('Insufficient leave balance for '.$column.'.');
                        }
                        $leaveBalance->{$fld} = floatval($leaveBalance->{$fld} ?? 0) - $toDeduct;
                        $deductionLog[$colKey] = $toDeduct;
                    }

                    $leaveBalance->save();

                    $leave->status = 'approved';

                    // Persist deduction metadata on the leave to indicate official deduction at approval
                    if (\Schema::hasColumn('leave_requests', 'printing_deduction_applied')) {
                        $leave->printing_deduction_applied = true;
                    }
                    if (\Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
                        $leave->printing_deduction_details = json_encode($deductionLog);
                    }

                    // do NOT write updated balances into leave_requests table; balances are kept in leave_balances only
                    $leave->save();
                });
            }
        } catch (\Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }

        try {
            HRAuditTrail::create([
                'actor_user_id' => auth()->id(),
                'module' => 'leave',
                'action' => 'deduct_leave_balances',
                'target_type' => 'leave_request',
                'target_id' => $leave->id,
                'details' => [
                    'original_balances' => $originalBalances,
                    'printing_preview' => (! empty($leave->printing_deduction_details) ? json_decode($leave->printing_deduction_details, true) : []),
                    'leave_reason' => $leave->reason,
                    'deduction_details' => $deductionLog,
                    'leave_status' => $leave->status,
                    'approver_id' => auth()->id(),
                    'timestamp' => now()->toDateTimeString(),
                    'type_labels' => [
                        'VL' => 'Vacation Leave',
                        'SL' => 'Sick Leave',
                        'WLNS' => 'Wellness Leave',
                        'SPL' => 'Special Privilege Leave',
                        'CTO' => 'CTO',
                        'SP' => 'Solo Parent Leave',
                    ],
                ],
            ]);
        } catch (\Exception $ex) {
            Log::error('Failed to write HRAuditTrail for leave deduction', ['leave_id' => $leave->id, 'error' => $ex->getMessage()]);
        }

        try {
            app(LeaveLedgerService::class)->writeLedgerEntry([
                'user_id' => $leave->user_id,
                'transaction_date' => $leave->start_date ?? now()->toDateString(),
                'period_end_date' => $leave->end_date,
                'transaction_type' => 'LEAVE_USED',
                'leave_type' => $column ?? 'OTHER',
                'debit_vl' => $deductionLog['VL'] ?? 0,
                'debit_sl' => $deductionLog['SL'] ?? 0,
                'credit_vl' => 0,
                'credit_sl' => 0,
                'reference_id' => $leave->id,
                'reference_type' => 'leave_request',
                'created_by' => auth()->id(),
                'is_system' => false,
            ]);
        } catch (\Throwable $ex) {
            Log::error('LeaveLedger write failed on approval', ['leave_id' => $leave->id, 'error' => $ex->getMessage()]);
        }

        Log::info('Leave approved and credits deducted', [
            'leave_id' => $leave->id,
            'employee_id' => $user->id ?? null,
            'deduction_details' => $deductionLog,
            'leave_status' => $leave->status,
            'timestamp' => now()->toDateTimeString(),
        ]);

        $this->notifyLeaveApproval($leave, $leaveBalance);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Leave approved and balance updated.']);
        }

        return redirect()->back()->with('success', 'Leave approved and balance updated.');
    }

    /**
     * Generate an Excel file for a leave request using the LEAVE.xlsx template.
     * Saves the file to storage/app/leave/prints and returns it as a download.
     */
    public function generateExcelResponse(LeaveRequest $leave): StreamedResponse
    {
        $templatePath = storage_path('app/templates/LEAVE.xlsx');
        if (! file_exists($templatePath)) {
            abort(500, 'Leave Excel template not found.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheet(0);

        $employee = $leave->user;
        $checkMark = '✓';

        // --- 1. Header / Personal Info ---
        $lastName = $employee->last_name ?? '';
        $firstName = $employee->first_name ?? '';
        $middleName = $employee->middle_name ?? '';
        $fullName = trim("{$lastName}, {$firstName} {$middleName}");
        $sheet->setCellValue('E5', $fullName);

        // Department
        $departmentName = '';
        if ($employee && $employee->Dept_id) {
            $dept = Department::where('Dept_id', $employee->Dept_id)->first();
            $departmentName = $dept->Dept_name ?? '';
        }
        $sheet->setCellValue('B5', $departmentName);

        // Date of filing
        $dateFiled = $leave->date_filed
            ? Carbon::parse($leave->date_filed)->format('m/d/Y')
            : ($leave->created_at ? $leave->created_at->format('m/d/Y') : '');
        $sheet->setCellValue('D6', $dateFiled);

        // Position (from designation or plantilla)
        $position = $employee->designation ?? '';
        if (empty($position)) {
            $assignment = EmployeeAssignment::where('employee_id', $employee->id)
                ->whereNull('end_date')
                ->latest('start_date')
                ->first();
            if ($assignment && $assignment->plantilla_id) {
                $plantilla = Plantilla::find($assignment->plantilla_id);
                $position = $plantilla->title ?? '';
            }
        }
        $sheet->setCellValue('F6', $position);

        // Salary
        $salary = '';
        if (isset($assignment) && $assignment && $assignment->plantilla_id) {
            $plantilla = $plantilla ?? Plantilla::find($assignment->plantilla_id);
            if ($plantilla) {
                // The incumbent's own step, not the position's budgeted step -
                // matches PayrollComputationService's salary resolution so this
                // printed figure can never disagree with actual payroll.
                $matrix = SalaryMatrix::where('sg', $plantilla->salary_grade)
                    ->where('step', $assignment->step)
                    ->orderByDesc('effective_date')
                    ->first();
                $salary = $matrix ? number_format($matrix->amount, 2) : '';
            }
        }
        $sheet->setCellValue('K6', $salary);

        // --- 2. Leave Type Checkboxes (Column B) ---
        $leaveTypeRowMap = [
            'vacation leave' => 11,
            'vl' => 11,
            'mandatory/forced leave' => 13,
            'mandatory leave' => 13,
            'forced leave' => 13,
            'sick leave' => 15,
            'sl' => 15,
            'maternity leave' => 17,
            'paternity leave' => 19,
            'special privilege leave' => 21,
            'spl' => 21,
            'solo parent leave' => 23,
            'study leave' => 25,
            '10-day vawc leave' => 27,
            'vawc leave' => 27,
            'vawc' => 27,
            'rehabilitation privilege' => 29,
            'special leave benefits for women' => 31,
            'special emergency (calamity) leave' => 33,
            'calamity leave' => 33,
            'adoption leave' => 35,
            'wellness leave' => 39,
            'wlns' => 39,
            'others' => 39,
        ];

        $selectedTypes = array_filter(array_map('trim', explode(',', (string) $leave->leave_type)));
        $matchedOthers = false;
        foreach ($selectedTypes as $type) {
            $normalized = strtolower(trim($type));
            $row = $leaveTypeRowMap[$normalized] ?? null;
            if ($row) {
                if ($row === 39 && ($normalized === 'wellness leave' || $normalized === 'wlns')) {
                    $sheet->setCellValue('C40', 'Wellness');
                } elseif ($row === 39 && $normalized === 'others') {
                    if (! empty($leave->details_others_type)) {
                        $sheet->setCellValue('C40', $leave->details_others_type);
                    }
                } else {
                    $sheet->setCellValue("B{$row}", $checkMark);
                }
                if ($row === 39) {
                    $matchedOthers = true;
                }
            } else {
                // Unknown leave type → mark as Others (do not populate D39 cell)
                $sheet->setCellValue('B39', $checkMark);
                $matchedOthers = true;
            }
        }

        // Do not populate D39 (description cell) as it is not part of official layout.

        // --- 3. Details of Leave (right side) ---
        $location = strtolower(trim((string) ($leave->details_location ?? '')));
        $locationSpecify = $leave->details_location_specify ?? '';

        if (strpos($location, 'within') !== false || $location === 'within_the_philippines') {
            $sheet->setCellValue('H13', $checkMark);
            if ($locationSpecify) {
                $sheet->setCellValue('K13', $locationSpecify);
            }
        }
        if (strpos($location, 'abroad') !== false) {
            $sheet->setCellValue('H15', $checkMark);
            if ($locationSpecify) {
                $sheet->setCellValue('K15', $locationSpecify);
            }
        }

        // Sick leave details
        $sickTreatment = strtolower(trim((string) ($leave->details_sick_treatment ?? '')));
        $sickIllness = $leave->details_sick_illness ?? '';
        if (strpos($sickTreatment, 'hospital') !== false || $sickTreatment === 'in_hospital') {
            $sheet->setCellValue('H19', $checkMark);
            if ($sickIllness) {
                $sheet->setCellValue('K19', $sickIllness);
            }
        }
        if (strpos($sickTreatment, 'out') !== false || $sickTreatment === 'out_patient' || $sickTreatment === 'outpatient') {
            $sheet->setCellValue('H21', $checkMark);
            if ($sickIllness) {
                $sheet->setCellValue('K21', $sickIllness);
            }
        }

        // --- 4. Number of Working Days & Inclusive Dates ---
        $sheet->setCellValue('C44', $leave->total_days ?? '');

        $start = $leave->start_date ? Carbon::parse($leave->start_date)->format('m/d/Y') : '';
        $end = $leave->end_date ? Carbon::parse($leave->end_date)->format('m/d/Y') : '';
        $inclusiveDates = ($start && $end) ? "{$start} - {$end}" : ($start ?: $end);
        $sheet->setCellValue('C48', $inclusiveDates);

        // Commutation: Not Requested by default
        $sheet->setCellValue('H45', $checkMark);

        // --- 5. Leave Credit Certification (Section 7.A) ---

        // Filing-time snapshot first (see the PDF export above for why: this must reflect
        // the balance as of when THIS leave was filed, not today's live balance), falling
        // back to the live balance only for older rows filed before the snapshot existed.
        $empLB = $employee ? ($employee->leaveBalance ?? null) : null;
        $vlCurrent = floatval($leave->balance_vacation_leave ?? ($empLB->VL ?? 0));
        $slCurrent = floatval($leave->balance_sick_leave ?? ($empLB->SL ?? 0));
        // Prefer per-type deduction preview if available (from filing or allow-print preview)
        $preview = [];
        if (! empty($leave->printing_deduction_details)) {
            try {
                $preview = json_decode($leave->printing_deduction_details, true) ?: [];
            } catch (\Exception $e) {
                $preview = [];
            }
        }

        $lt = strtolower((string) ($leave->leave_type ?? ''));
        $vlRequested = isset($preview['VL']) ? floatval($preview['VL']) : ((stripos($lt, 'vacation') !== false || stripos($lt, 'vl') !== false) ? floatval($leave->paid_days ?? 0) : 0.0);
        $slRequested = isset($preview['SL']) ? floatval($preview['SL']) : ((stripos($lt, 'sick') !== false || stripos($lt, 'sl') !== false) ? floatval($leave->paid_days ?? 0) : 0.0);

        $vlBalance = $vlCurrent - $vlRequested;
        $slBalance = $slCurrent - $slRequested;
        // As of date
        $sheet->setCellValue('D53', $dateFiled);
        $sheet->setCellValue('D56', $this->formatBalance($vlCurrent));
        $sheet->setCellValue('E56', $this->formatBalance($slCurrent));
        $sheet->setCellValue('D57', $this->formatBalance($vlRequested));
        $sheet->setCellValue('E57', $this->formatBalance($slRequested));
        $sheet->setCellValue('D58', $this->formatBalance($vlBalance));
        $sheet->setCellValue('E58', $this->formatBalance($slBalance));

        Log::info('Leave Excel print Section 7.A values', [
            'leave_id' => $leave->id,
            'employee_id' => $employee->id ?? null,
            'vl_total_earned' => $vlCurrent,
            'vl_requested' => $vlRequested,
            'vl_balance' => $vlBalance,
            'sl_total_earned' => $slCurrent,
            'sl_requested' => $slRequested,
            'sl_balance' => $slBalance,
            'printing_allowed' => (bool) ($leave->printing_allowed ?? false),
            'leave_status' => $leave->status,
        ]);

        // HR Manager name and designation
        $siteSettings = Setting::first();
        if ($siteSettings && ! empty($siteSettings->hr_manager_name)) {
            $sheet->mergeCells('C59:E59');
            $sheet->setCellValue('C59', $siteSettings->hr_manager_name);
            $sheet->getStyle('C59')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->setCellValue('C60', $siteSettings->hr_manager_designation ?? 'OIC-CHRMD');

        // --- 6. Recommendation (Section 7.B) ---
        $status = strtolower($leave->status ?? '');
        if ($status === 'approved' || $leave->detailed_status === 'Approved') {
            $sheet->setCellValue('H53', $checkMark);
        } elseif ($status === 'rejected' || $leave->detailed_status === 'Disapproved') {
            $sheet->setCellValue('H55', $checkMark);
            if ($leave->rejection_notes) {
                $sheet->setCellValue('I56', $leave->rejection_notes);
            }
        }

        // --- 7. Approved / Disapproved (Section 7.C / 7.D) ---
        $paidDays = $leave->paid_days ?? 0;
        $lwopDays = $leave->lwop_days ?? 0;

        if ($status === 'approved') {
            if ($paidDays > 0) {
                $label = (string) $sheet->getCell('C62')->getValue();
                $sheet->setCellValue('C62', preg_replace('/_+/', $this->formatBalance($paidDays), $label, 1));
            }
            if ($lwopDays > 0) {
                $label = (string) $sheet->getCell('C63')->getValue();
                $sheet->setCellValue('C63', preg_replace('/_+/', $this->formatBalance($lwopDays), $label, 1));
            }
        } elseif ($status === 'rejected') {
            $sheet->setCellValue('I62', $leave->rejection_notes ?? '');
        }

        // --- 8. Signatories ---
        $officialLabel = '';
        if (isset($dept)) {
            // Department head name for recommendation — skipped entirely when the applicant
            // is themselves a department head/HR manager, since that leave is routed straight
            // to the Mayor and never goes through a department-head recommendation step.
            $applicantRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
            if (! in_array($applicantRole, ['department head', 'hr manager'], true) && ! empty($dept->EmpNo) && $dept->EmpNo !== 'UNASSIGNED') {
                $headUser = User::where('EmpNo', $dept->EmpNo)->first();
                if ($headUser && $headUser->access_level === 'department head') {
                    $headParts = array_filter([
                        $headUser->first_name ?? '',
                        $headUser->middle_name ?? '',
                        $headUser->last_name ?? '',
                    ]);
                    $deptHeadName = implode(' ', $headParts);
                    if (empty($deptHeadName)) {
                        $deptHeadName = $headUser->name ?? '';
                    }
                    $sheet->setCellValue('I59', $deptHeadName);
                    if (! empty($headUser->designation)) {
                        $sheet->setCellValue('H60', $headUser->designation);
                    }
                }
            }

            // Mayor/Vice Mayor signatory resolved via recursive hierarchy
            [$sigName, $sigDesignation, $officialLabel] = $this->resolveExecutiveSignatory($dept, $siteSettings);
            if ($sigName !== '') {
                $sheet->setCellValue('D66', $sigName);
            }
            if ($sigDesignation !== '') {
                $sheet->setCellValue('D67', $sigDesignation);
            }
        }

        // --- 9. Apply protection & Stream ---
        $lockApplied = false;
        try {
            $this->protectAllSheets($spreadsheet, $employee);
            $lockApplied = true;
        } catch (\Exception $e) {
            Log::warning('Leave sheet protection failed', [
                'leave_request_id' => $leave->id,
                'error' => $e->getMessage(),
            ]);
        }

        $filename = "Leave_Form_{$leave->id}_".now()->format('Ymd_His').'.xlsx';

        // Audit log
        $user = auth()->user();
        Log::info('Leave form printed (Excel)', [
            'leave_request_id' => $leave->id,
            'printed_by' => $user->id ?? null,
            'role' => $user->access_level ?? null,
            'timestamp' => now()->toDateTimeString(),
            'filename' => $filename,
            'lock_applied' => $lockApplied,
            'format_preserved' => true,
            'official_included' => $officialLabel ?: null,
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

    /**
     * Format a numeric balance value with up to 3 decimal places.
     */
    private function formatBalance($val): string
    {
        if (! is_numeric($val)) {
            return (string) $val;
        }
        $float = (float) $val;
        if ($float == (int) $float) {
            return (string) (int) $float;
        }

        return rtrim(rtrim(number_format($float, $this->leaveDecimals(), '.', ''), '0'), '.');
    }

    /**
     * Walk the department parent chain to find the root (top-level) department.
     * Returns the root Department or null. Limits depth to prevent infinite loops.
     */
    private function resolveRootDepartment(?Department $dept, int $maxDepth = 10): ?Department
    {
        if (! $dept) {
            return null;
        }

        $current = $dept;
        $visited = [];

        while ($current->parent_dept_id && $maxDepth-- > 0) {
            if (in_array($current->parent_dept_id, $visited, true)) {
                break; // circular reference guard
            }
            $visited[] = $current->Dept_id;
            $parent = Department::where('Dept_id', $current->parent_dept_id)->first();
            if (! $parent) {
                break;
            }
            $current = $parent;
        }

        return $current;
    }

    /**
     * Determine whether a department falls under the Vice Mayor's office
     * by walking its parent chain to the root and checking the name.
     */
    private function isUnderViceMayor(?Department $dept): bool
    {
        $root = $this->resolveRootDepartment($dept);
        if (! $root) {
            return false;
        }

        $name = strtolower(str_replace(['-', '_'], ' ', trim($root->Dept_name ?? '')));

        return str_contains($name, 'vice mayor') || str_contains($name, 'vice-mayor');
    }

    /**
     * Resolve the executive signatory (Mayor or Vice Mayor) for a department.
     * Returns [name, designation, label] where label is 'City Mayor' or 'City Vice Mayor'.
     */
    private function resolveExecutiveSignatory(?Department $dept, ?Setting $settings): array
    {
        if (! $settings) {
            return ['', '', ''];
        }

        if ($this->isUnderViceMayor($dept)) {
            return [
                $settings->vice_mayor_name ?? '',
                $settings->vice_mayor_designation ?? '',
                'City Vice Mayor',
            ];
        }

        return [
            $settings->mayor_name ?? '',
            $settings->mayor_designation ?? '',
            'City Mayor',
        ];
    }

    /**
     * Lock all sheets in the spreadsheet to prevent editing.
     */
    private function protectAllSheets(Spreadsheet $spreadsheet, ?User $employee): void
    {
        $first = $employee->first_name ?? ($employee->firstname ?? '');
        $last = $employee->last_name ?? ($employee->lastname ?? '');
        $password = strtoupper($first.substr((string) $last, 0, 1));

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

    /**
     * Send an approval notification to the leave owner.
     * Extracted from three identical blocks inside approveLeave().
     *
     * @param  LeaveBalance  $leaveBalance
     */
    private function notifyLeaveApproval(LeaveRequest $leave, object $leaveBalance): void
    {
        $employee = $leave->user;

        if (! $employee) {
            return;
        }

        $formatted = [
            'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
            'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
            'end' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
        ];

        try {
            $employee->notify(new HrisTransactionNotification(
                requestType: 'Leave Request',
                status: 'Approved',
                details: [
                    'Leave Type' => $leave->leave_type ?? 'N/A',
                    'Start Date' => $formatted['start'],
                    'End Date' => $formatted['end'],
                    'Date Filed' => $formatted['filed'],
                    'VL Balance' => number_format((float) ($leaveBalance->VL ?? 0), $this->leaveDecimals()),
                    'SL Balance' => number_format((float) ($leaveBalance->SL ?? 0), 0),
                ],
            ));
        } catch (\Exception $e) {
            Log::error('Failed to send leave approval notification', [
                'leave_id' => $leave->id,
                'user_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
