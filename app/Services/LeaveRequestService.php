<?php

namespace App\Services;

use App\Jobs\SignESignatureRequestPdfJob;
use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\EsignatureSigning;
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
use App\Support\LeaveTypeResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    public function canPrint(LeaveRequest $leave, User $user, bool $requireCertification = true): bool
    {
        // do not allow printing for declined or cancelled requests
        if (in_array($leave->status, ['declined', 'cancelled', 'rejected'], true)) {
            return false;
        }

        // A leave filed with e-signature intent must be certified in Leave Credit
        // Certification before it can be printed - unconditional, same as the
        // declined/cancelled check above, so it also closes the AO/HR-Manager/DH
        // "approved leave, no printing_allowed needed" branches below, not just the
        // printing_allowed-gated ones. $requireCertification is only ever false for
        // startEsignaturePrint() (retrying the applicant's OWN signature - a different
        // concern from HR/LM certifying the document, and the two may land in either
        // order) - see LeaveRequestController::startEsignaturePrint().
        if ($requireCertification && $this->needsCertificationBeforePrinting($leave)) {
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
     * True iff this leave was filed with e-signature intent but hasn't yet been
     * certified in Leave Credit Certification - the print/allow-printing gate this
     * feeds is deliberately independent of the leave's status (pending/approved/etc),
     * unlike pendingCertificationQuery() (scoped to status='pending' for the
     * certification queue listing itself) - see canPrint() and both
     * DepartmentHeadController/AdministrativeOfficerController::allowPrinting().
     */
    public function needsCertificationBeforePrinting(LeaveRequest $leave): bool
    {
        if (! $leave->esignature_requested_at) {
            return false;
        }

        return ! EsignatureSigning::where('signable_type', LeaveRequest::class)
            ->where('signable_id', $leave->id)
            ->where('field_name', 'CertifyingSignature')
            ->where('status', EsignatureSigning::STATUS_COMPLETED)
            ->exists();
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
                        $lb = LeaveBalance::where('user_id', $original->user_id)->lockForUpdate()->first();
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

                        app(LeaveLedgerService::class)->writeLedgerEntry([
                            'user_id' => $original->user_id,
                            'transaction_date' => $datesToCancel->min('leave_date') ?? now()->toDateString(),
                            'period_end_date' => $datesToCancel->max('leave_date'),
                            'transaction_type' => 'LEAVE_CANCELLED',
                            'leave_type' => ! empty($restored) ? implode('+', array_keys($restored)) : 'VL',
                            'credit_vl' => floatval($restored['VL'] ?? 0),
                            'credit_sl' => floatval($restored['SL'] ?? 0),
                            'credit_wlns' => floatval($restored['WLNS'] ?? 0),
                            'credit_spl' => floatval($restored['SPL'] ?? 0),
                            'credit_cto' => floatval($restored['CTO'] ?? 0),
                            'credit_sp' => floatval($restored['SP'] ?? 0),
                            'debit_vl' => 0,
                            'debit_sl' => 0,
                            'reference_id' => $original->id,
                            'reference_type' => 'leave_request',
                            'created_by' => auth()->id(),
                            'is_system' => false,
                            'remarks' => 'Leave rescheduled',
                        ]);
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
        // Deliberately unlocked here - this is only used for the null-check, the two
        // no-deduction early-return paths below, and an audit-log "original balance"
        // snapshot. The real, lock-protected fetch happens inside each DB::transaction()
        // below, immediately before the actual deduction - locking here would acquire
        // and release the row lock before that transaction even opens, giving no real
        // protection while looking like it does.
        $leaveBalance = LeaveBalance::where('user_id', $user->id)->first();
        if (! $leaveBalance) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'No leave balance record found for this user.'], 422);
            }

            return redirect()->back()->with('error', 'No leave balance record found for this user.');
        }

        $column = null;
        $label = strtolower($leave->leave_type ?? '');

        // Maternity / Special Leave (Gynecological) / Study-Examination / Rehabilitation Privilege
        // must never deduct from any balance. Skip the substring keyword scan entirely for them —
        // "Special Leave (Gynecological)" contains "special" and "Rehabilitation Privilege" contains
        // "privilege", both of which would otherwise falsely collide with the SPL keywords below.
        $isNonDeductibleType = in_array($label, array_map('strtolower', LeaveTypeResolver::NON_DEDUCTIBLE_TYPES), true);

        if (! $isNonDeductibleType) {
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
            if ($isNonDeductibleType) {
                // Record the approval for HR reporting/history even though it never touches leave_balances.
                app(LeaveLedgerService::class)->writeLedgerEntry([
                    'user_id' => $leave->user_id,
                    'transaction_date' => $leave->start_date ?? now()->toDateString(),
                    'period_end_date' => $leave->end_date,
                    'transaction_type' => 'LEAVE_USED',
                    'leave_type' => $leave->leave_type,
                    'debit_vl' => 0,
                    'debit_sl' => 0,
                    'credit_vl' => 0,
                    'credit_sl' => 0,
                    'reference_id' => $leave->id,
                    'reference_type' => 'leave_request',
                    'created_by' => auth()->id(),
                    'is_system' => false,
                ]);
            }
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
                DB::transaction(function () use ($preview, $leave, &$deductionLog, $resolveField, $column) {
                    // Fetch a fresh, locked copy right before the mutation - the plain
                    // fetch above is too early (outside this transaction) to protect
                    // against a concurrent approval racing this same balance row.
                    $leaveBalance = LeaveBalance::where('user_id', $leave->user_id)->lockForUpdate()->first();
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

                    app(LeaveLedgerService::class)->writeLedgerEntry([
                        'user_id' => $leave->user_id,
                        'transaction_date' => $leave->start_date ?? now()->toDateString(),
                        'period_end_date' => $leave->end_date,
                        'transaction_type' => 'LEAVE_USED',
                        'leave_type' => ! empty($deductionLog) ? implode('+', array_keys($deductionLog)) : ($column ?? 'OTHER'),
                        'debit_vl' => $deductionLog['VL'] ?? 0,
                        'debit_sl' => $deductionLog['SL'] ?? 0,
                        'debit_wlns' => $deductionLog['WLNS'] ?? 0,
                        'debit_spl' => $deductionLog['SPL'] ?? 0,
                        'debit_cto' => $deductionLog['CTO'] ?? 0,
                        'debit_sp' => $deductionLog['SP'] ?? 0,
                        'credit_vl' => 0,
                        'credit_sl' => 0,
                        'reference_id' => $leave->id,
                        'reference_type' => 'leave_request',
                        'created_by' => auth()->id(),
                        'is_system' => false,
                    ]);
                });
            } else {
                // Fallback: previous single-column deduction behavior
                DB::transaction(function () use ($column, $toDeduct, $leave, &$deductionLog, $resolveField) {
                    // Fetch a fresh, locked copy right before the mutation - see the
                    // preview-based branch above for why the plain fetch above is
                    // too early to protect this deduction.
                    $leaveBalance = LeaveBalance::where('user_id', $leave->user_id)->lockForUpdate()->first();
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

                    app(LeaveLedgerService::class)->writeLedgerEntry([
                        'user_id' => $leave->user_id,
                        'transaction_date' => $leave->start_date ?? now()->toDateString(),
                        'period_end_date' => $leave->end_date,
                        'transaction_type' => 'LEAVE_USED',
                        'leave_type' => ! empty($deductionLog) ? implode('+', array_keys($deductionLog)) : ($column ?? 'OTHER'),
                        'debit_vl' => $deductionLog['VL'] ?? 0,
                        'debit_sl' => $deductionLog['SL'] ?? 0,
                        'debit_wlns' => $deductionLog['WLNS'] ?? 0,
                        'debit_spl' => $deductionLog['SPL'] ?? 0,
                        'debit_cto' => $deductionLog['CTO'] ?? 0,
                        'debit_sp' => $deductionLog['SP'] ?? 0,
                        'credit_vl' => 0,
                        'credit_sl' => 0,
                        'reference_id' => $leave->id,
                        'reference_type' => 'leave_request',
                        'created_by' => auth()->id(),
                        'is_system' => false,
                    ]);
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
     * Approves a leave exactly as approveLeave() does (same printing_allowed gate,
     * balance deduction, ledger write, notification - reused unchanged), and, once
     * that succeeds, kicks off an EsignatureSigning attempt for the approving
     * officer's own saved PNPKI certificate. The officer is a genuine SECOND signer,
     * not a replacement of the applicant's own signature: if the applicant already
     * e-signed this leave at filing, the officer's signature is added on top of that
     * already-signed PDF via a second pyHanko addsig pass (SignESignatureRequestPdfJob
     * resolves the signer from EsignatureSigning.requested_by, and each addsig pass
     * is a genuine incremental update that leaves an earlier signature intact and
     * independently valid - confirmed empirically before this was built). Otherwise
     * the officer's signature is the first one, signing a fresh render that now
     * includes Section 7 (recommendation/approved days/balance), since buildEsignaturePdfBytes()
     * fills those in based on $leave->status, which approveLeave() just flipped to
     * 'approved'.
     *
     * A wrong password never touches approval state - verified against the officer's
     * saved certificate before approveLeave() is even called. A password that's
     * merely wrong but the officer does have a saved certificate is the normal,
     * expected case this guards against; approveLeave() itself is left completely
     * unmodified by this method.
     */
    public function approveLeaveWithEsignature(Request $request, int $id, string $password, ESignatureCredentialStore $credentialStore)
    {
        $leave = LeaveRequest::findOrFail($id);
        $wasAlreadyApproved = $leave->status === 'approved';

        $actor = auth()->user();
        $setting = $actor->esignatureSetting;
        if (! $setting) {
            return response()->json(['success' => false, 'message' => 'You have not set up an e-signature yet.'], 422);
        }

        try {
            $certificateBytes = $credentialStore->retrieveDecrypted($setting->certificate_path);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not read your saved certificate. Please contact HR or re-save your e-signature setting.'], 422);
        }

        if (! $credentialStore->verifyPassword($certificateBytes, $password)) {
            return response()->json(['success' => false, 'message' => 'That password did not unlock your saved certificate. Please check the password and try again.'], 422);
        }

        $approvalResponse = $this->approveLeave($request, $id);

        if ($wasAlreadyApproved || ! $this->isSuccessfulJsonResponse($approvalResponse)) {
            return $approvalResponse;
        }

        $leave->refresh();

        // Always dispatched as a genuine co-signing pass (field name 'ApproverSignature'),
        // regardless of whether the applicant's own signature has completed yet -
        // SignESignatureRequestPdfJob resolves what to build on top of (or falls back to
        // a fresh render) at job-execution time, not here, to avoid racing the
        // applicant's own signing job. See dispatchCoSigningPass()'s docblock.
        $mapping = $this->loadLeaveMapping();
        $signing = $this->dispatchCoSigningPass($leave, $actor, $password, 'ApproverSignature', $mapping['approver_signature_field'] ?? null);

        return response()->json([
            'success' => true,
            'signing_id' => $signing->id,
            'status_url' => route('esignature-signings.status', $signing->id),
            'message' => 'Leave approved. Signing in progress.',
        ]);
    }

    /**
     * Redoes the Department Head's own co-signature for an already-approved leave,
     * without re-running approveLeave() itself - for recovering a co-signing pass
     * that ended up orphaned (see dispatchCoSigningPass()'s race-condition fix) or
     * genuinely failed, since approveLeaveWithEsignature() only ever dispatches a
     * co-sign once, at the moment the leave transitions to approved (it returns
     * early via $wasAlreadyApproved otherwise, with no other recovery path).
     */
    public function retryApproverCoSignature(LeaveRequest $leave, User $actor, string $password, ESignatureCredentialStore $credentialStore)
    {
        if ($leave->status !== 'approved' || ! $leave->esignature_requested_at) {
            return response()->json(['success' => false, 'message' => 'This leave is not in a state that can be co-signed.'], 422);
        }

        $alreadyCoSigned = EsignatureSigning::where('signable_type', LeaveRequest::class)
            ->where('signable_id', $leave->id)
            ->where('field_name', 'ApproverSignature')
            ->where('status', EsignatureSigning::STATUS_COMPLETED)
            ->exists();

        if ($alreadyCoSigned) {
            return response()->json(['success' => false, 'message' => 'This leave already has a completed Department Head co-signature.'], 422);
        }

        $setting = $actor->esignatureSetting;
        if (! $setting) {
            return response()->json(['success' => false, 'message' => 'You have not set up an e-signature yet.'], 422);
        }

        try {
            $certificateBytes = $credentialStore->retrieveDecrypted($setting->certificate_path);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not read your saved certificate. Please contact HR or re-save your e-signature setting.'], 422);
        }

        if (! $credentialStore->verifyPassword($certificateBytes, $password)) {
            return response()->json(['success' => false, 'message' => 'That password did not unlock your saved certificate. Please check the password and try again.'], 422);
        }

        $mapping = $this->loadLeaveMapping();
        $signing = $this->dispatchCoSigningPass($leave, $actor, $password, 'ApproverSignature', $mapping['approver_signature_field'] ?? null);

        return response()->json([
            'success' => true,
            'signing_id' => $signing->id,
            'status_url' => route('esignature-signings.status', $signing->id),
            'message' => 'Co-signing in progress.',
        ]);
    }

    /**
     * Queues a real PNPKI co-signature for the "Certification of Leave Credits" line
     * (leave_mapping.php's hr_certification_signature_field) from whichever HR Manager
     * actually signs the certification batch queue
     * (LeaveCertificationController::batchSign()) - see that controller, and
     * forwardedForSigningQuery() below, for how the sign queue is derived. Always uses
     * the fixed 'CertifyingSignature' field name regardless of whether it ends up being
     * the first signature on the document or a later layer, unlike the DH/AO co-sign
     * above - it must never fall back to the reserved null/'Signature' name reserved
     * for the applicant's own filing signature.
     */
    public function certifyLeaveCredits(LeaveRequest $leave, User $signer, string $password): EsignatureSigning
    {
        $mapping = $this->loadLeaveMapping();

        return $this->dispatchCoSigningPass($leave, $signer, $password, 'CertifyingSignature', $mapping['hr_certification_signature_field'] ?? null);
    }

    /**
     * Query builder for leaves filed with e-signature intent that don't yet have an
     * active or completed 'CertifyingSignature' EsignatureSigning row - a derived
     * "queue" rather than a persisted one, so a failed attempt naturally reappears here
     * on the next batch run with no separate retry bookkeeping. Only leaves filed with
     * e-signature intent are eligible: buildEsignaturePdfBytes()/the whole PNPKI PDF
     * only exists for those - everything else prints via the older Excel path instead.
     *
     * Scoped to status='pending' only (not 'approved'/'cancelled'/'declined'/
     * 'disapproved') - certification is meant to happen right after filing, on the
     * figures as filed. A first pass here left this unscoped by status entirely
     * (matching the old static "HR Manager" printed text's unconditional behavior),
     * which surfaced a one-time backlog dominated by already-cancelled leaves (74% of
     * it, in practice) that will never become an official record worth certifying.
     *
     * Exposed as a query builder (not just pendingCertificationLeaves() below) so the
     * sidebar's pending-count badge can reuse this exact definition via ->count()
     * instead of duplicating the query inline - the ETA/Locator "pending" badge counts
     * already drifted out of sync with their real page's own query once before by doing
     * that (see CLAUDE.md's ETA/Locator "Gotcha" notes).
     */
    public function pendingCertificationQuery(): Builder
    {
        return LeaveRequest::where('status', 'pending')
            ->whereNotNull('esignature_requested_at')
            ->whereDoesntHave('esignatureSignings', fn ($q) => $q
                ->where('field_name', 'CertifyingSignature')
                ->whereIn('status', [EsignatureSigning::STATUS_PENDING, EsignatureSigning::STATUS_PROCESSING, EsignatureSigning::STATUS_COMPLETED]));
    }

    public function pendingCertificationLeaves(): Collection
    {
        return $this->pendingCertificationQuery()->with('user')->orderBy('date_filed')->get();
    }

    /**
     * The Leave Manager's own review queue - eligible leaves that haven't been
     * reviewed yet (certification_review_status is still null). Layered on top of
     * pendingCertificationQuery() rather than folded into it, so that base query keeps
     * meaning "eligible for certification, not yet signed" regardless of review state.
     */
    public function pendingReviewQuery(): Builder
    {
        return $this->pendingCertificationQuery()->whereNull('certification_review_status');
    }

    /**
     * The HR Manager's sign queue - eligible leaves the Leave Manager has forwarded.
     * batchCertifyPendingLeaves() only ever signs leaves out of this query, never a
     * merely-pending, not-yet-reviewed one.
     */
    public function forwardedForSigningQuery(): Builder
    {
        return $this->pendingCertificationQuery()->where('certification_review_status', 'forwarded');
    }

    /**
     * Leaves the Leave Manager has rejected with a reason - visible to both roles on
     * the Rejected tab, where either an HR Manager or the Leave Manager can send one
     * back to pendingReviewQuery() via reopenCertification().
     */
    public function rejectedCertificationQuery(): Builder
    {
        return $this->pendingCertificationQuery()->where('certification_review_status', 'rejected');
    }

    /**
     * Department + employee name/EmpNo search, shared by the paginated pending and
     * history views below so the two lists can't drift on what "matches the filter"
     * means (the same class of drift CLAUDE.md warns about for the ETA/Locator badge
     * counts) - both filter against the underlying LeaveRequest's owning user.
     *
     * @param  array{department?: int|string|null, search?: string|null}  $filters
     */
    private function applyCertificationFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['department'])) {
            $query->whereHas('user', fn ($q) => $q->where('Dept_id', $filters['department']));
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('user', fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('EmpNo', 'like', "%{$search}%");
            }));
        }

        return $query;
    }

    /**
     * Paginated, filterable view of pendingReviewQuery() for the Leave Manager's
     * "Pending Review" tab - kept separate from pendingCertificationLeaves() above (an
     * unfiltered/unpaginated collection, used elsewhere including tests) so adding
     * filters/pagination here doesn't change that method's existing contract. Uses a
     * named page parameter ('pending_page') since the same page also paginates
     * forwarded/rejected/history lists independently - a shared plain 'page' param
     * would collide.
     *
     * @param  array{department?: int|string|null, search?: string|null}  $filters
     */
    public function paginatedPendingCertificationLeaves(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->applyCertificationFilters($this->pendingReviewQuery(), $filters)
            ->with(['user.department', 'leaveDates']);

        return $query->orderBy('date_filed')->paginate($perPage, ['*'], 'pending_page')->withQueryString();
    }

    /**
     * Paginated, filterable view of forwardedForSigningQuery() - the HR Manager's
     * sign queue, and (read-only) the Leave Manager's own visibility into what they've
     * already forwarded. Named page parameter 'forwarded_page', same reasoning as
     * paginatedPendingCertificationLeaves() above.
     *
     * @param  array{department?: int|string|null, search?: string|null}  $filters
     */
    public function paginatedForwardedForSigning(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->applyCertificationFilters($this->forwardedForSigningQuery(), $filters)
            ->with(['user.department', 'certificationReviewedBy', 'leaveDates']);

        return $query->orderBy('certification_reviewed_at')->paginate($perPage, ['*'], 'forwarded_page')->withQueryString();
    }

    /**
     * Paginated, filterable view of rejectedCertificationQuery() - the Rejected tab,
     * visible to both roles. Named page parameter 'rejected_page', same reasoning as
     * paginatedPendingCertificationLeaves() above.
     *
     * @param  array{department?: int|string|null, search?: string|null}  $filters
     */
    public function paginatedRejectedCertifications(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->applyCertificationFilters($this->rejectedCertificationQuery(), $filters)
            ->with(['user.department', 'certificationReviewedBy', 'leaveDates']);

        return $query->latest('certification_reviewed_at')->paginate($perPage, ['*'], 'rejected_page')->withQueryString();
    }

    /**
     * Paginated, filterable list of completed 'CertifyingSignature' signings. Filters
     * against a LeaveRequest subquery (applyCertificationFilters()) rather than a
     * nested whereHas('signable.user', ...) - EsignatureSigning.signable is a genuine
     * polymorphic morphTo, and Eloquent can't build a plain nested whereHas() through
     * one without whereHasMorph()'s extra ceremony; a subquery sidesteps that entirely
     * and reads the same either way since only LeaveRequest ever populates this table
     * today (see EsignatureSigning's own class docblock).
     *
     * @param  array{department?: int|string|null, search?: string|null}  $filters
     */
    public function paginatedCertificationHistory(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $leaveIdsQuery = $this->applyCertificationFilters(LeaveRequest::query(), $filters)->select('id');

        $query = EsignatureSigning::where('signable_type', LeaveRequest::class)
            ->whereIn('signable_id', $leaveIdsQuery)
            ->where('field_name', 'CertifyingSignature')
            ->where('status', EsignatureSigning::STATUS_COMPLETED)
            ->with(['signable.user.department', 'signable.leaveDates', 'signable.certificationReviewedBy', 'requestedBy']);

        return $query->latest('completed_at')->paginate($perPage, ['*'], 'history_page')->withQueryString();
    }

    public function certificationFilterDepartments(): Collection
    {
        return Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);
    }

    /**
     * Leave Manager review action: declines a leave's certification with a required
     * reason. Only valid from pendingReviewQuery() - a leave already forwarded,
     * rejected, or signed can't be rejected again. Terminal-but-reversible: the leave
     * moves to rejectedCertificationQuery() rather than disappearing, and either role
     * can send it back via reopenCertification() once the underlying issue is
     * resolved. No employee notification is sent - this is internal HR/Leave Manager
     * coordination, same as the rest of this queue.
     */
    public function rejectCertification(LeaveRequest $leave, User $actor, string $remarks): void
    {
        if (! $this->pendingReviewQuery()->whereKey($leave->id)->exists()) {
            throw new \RuntimeException('This leave is no longer awaiting certification review.');
        }

        $leave->update([
            'certification_review_status' => 'rejected',
            'certification_reviewed_by' => $actor->id,
            'certification_reviewed_at' => now(),
            'certification_review_remarks' => $remarks,
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'esignature',
            'action' => 'leave_certification_rejected',
            'target_type' => LeaveRequest::class,
            'target_id' => $leave->id,
            'details' => ['remarks' => $remarks],
        ]);
    }

    /**
     * Leave Manager review action: clears one or more leaves for the HR Manager to
     * sign. $leaveIds follows the same "intersect with pendingReviewQuery(), never
     * trust the client id list outright" convention as batchCertifyPendingLeaves()
     * below - null forwards everything currently pending review, an empty array
     * forwards nothing. Unlike reject, no reason is collected.
     *
     * @param  array<int, int>|null  $leaveIds
     * @return array{processed: array<int, int>, errors: array<int, array{leave_id: int, message: string}>}
     */
    public function forwardCertifications(User $actor, ?array $leaveIds = null): array
    {
        $query = $this->pendingReviewQuery();
        if ($leaveIds !== null) {
            $query->whereIn('id', $leaveIds);
        }

        $processed = [];
        $errors = [];

        foreach ($query->get() as $leave) {
            try {
                $leave->update([
                    'certification_review_status' => 'forwarded',
                    'certification_reviewed_by' => $actor->id,
                    'certification_reviewed_at' => now(),
                    'certification_review_remarks' => null,
                ]);
                $processed[] = $leave->id;
            } catch (\Throwable $e) {
                $errors[] = ['leave_id' => $leave->id, 'message' => $e->getMessage()];
            }
        }

        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'esignature',
            'action' => 'leave_certification_forwarded',
            'target_type' => 'leave_certification_batch',
            'target_id' => null,
            'details' => ['leave_ids' => $processed, 'error_count' => count($errors)],
        ]);

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Sends a rejected leave back to pendingReviewQuery() - available to either an
     * HR Manager or the Leave Manager, since either may be the one to notice the
     * underlying issue has been resolved. Resets all four review columns to null
     * rather than keeping a history row (the row's own current state is single-slot,
     * same as cancellation_status elsewhere on this model); the prior remarks are
     * captured into the audit row before being cleared, so the reason isn't lost.
     */
    public function reopenCertification(LeaveRequest $leave, User $actor): void
    {
        if ($leave->certification_review_status !== 'rejected') {
            throw new \RuntimeException('This leave is not currently rejected.');
        }

        $previousRemarks = $leave->certification_review_remarks;

        $leave->update([
            'certification_review_status' => null,
            'certification_reviewed_by' => null,
            'certification_reviewed_at' => null,
            'certification_review_remarks' => null,
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'esignature',
            'action' => 'leave_certification_reopened',
            'target_type' => LeaveRequest::class,
            'target_id' => $leave->id,
            'details' => ['previous_remarks' => $previousRemarks],
        ]);
    }

    /**
     * The certification signature is always $actor's own - whichever HR Manager is
     * logged in signs with their own saved certificate, exactly like DH/AO co-signing
     * (approveLeaveWithEsignature() above). Only ever signs leaves already forwarded
     * by a Leave Manager (forwardedForSigningQuery()) - a merely-pending, not-yet-
     * reviewed leave is never eligible here regardless of what $leaveIds requests.
     *
     * $leaveIds, when given, is always intersected with forwardedForSigningQuery()
     * rather than trusted outright - a stale/already-signed/not-yet-forwarded/foreign
     * id submitted by the client is silently dropped (it simply doesn't match the
     * query) instead of erroring or being processed twice. Null signs everything
     * currently forwarded, matching the original "Sign All Pending" behavior; an empty
     * array signs nothing (e.g. the user unchecked every row), which is treated the
     * same as an already-empty queue rather than an error.
     *
     * @param  array<int, int>|null  $leaveIds
     * @return array{processed: array<int, int>, errors: array<int, array{leave_id: int, message: string}>}
     */
    public function batchCertifyPendingLeaves(User $actor, string $password, ESignatureCredentialStore $credentialStore, ?array $leaveIds = null): array
    {
        $setting = $actor->esignatureSetting;
        if (! $setting) {
            throw new \RuntimeException('You have not set up an e-signature yet.');
        }

        try {
            $certificateBytes = $credentialStore->retrieveDecrypted($setting->certificate_path);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not read your saved certificate. Please contact HR or re-save your e-signature setting.');
        }

        if (! $credentialStore->verifyPassword($certificateBytes, $password)) {
            throw new \RuntimeException('That password did not unlock your saved certificate. Please check the password and try again.');
        }

        $query = $this->forwardedForSigningQuery();
        if ($leaveIds !== null) {
            $query->whereIn('id', $leaveIds);
        }

        $processed = [];
        $errors = [];

        foreach ($query->with('user')->orderBy('date_filed')->get() as $leave) {
            try {
                $this->certifyLeaveCredits($leave, $actor, $password);
                $processed[] = $leave->id;
            } catch (\Throwable $e) {
                $errors[] = ['leave_id' => $leave->id, 'message' => $e->getMessage()];
            }
        }

        $this->logCertificationBatchTrigger($actor, $processed, $errors);

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * $actor is always the HR Manager who both triggered and signed the batch (see
     * batchCertifyPendingLeaves()) - unlike the review-step audit rows above, there's
     * no separate signer to distinguish here any more.
     *
     * @param  array<int, int>  $processedIds
     * @param  array<int, array{leave_id: int, message: string}>  $errors
     */
    private function logCertificationBatchTrigger(User $actor, array $processedIds, array $errors): void
    {
        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'esignature',
            'action' => 'leave_certification_batch_triggered',
            'target_type' => 'leave_certification_batch',
            'target_id' => null,
            'details' => [
                'leave_ids' => $processedIds,
                'error_count' => count($errors),
            ],
        ]);
    }

    private function loadLeaveMapping(): array
    {
        $mappingFile = storage_path('app/templates/leave_mapping.php');

        return file_exists($mappingFile) ? include $mappingFile : [];
    }

    /**
     * Shared co-signing mechanism behind approveLeaveWithEsignature(),
     * certifyLeaveCredits(), and retryApproverCoSignature(): creates a pending
     * signing row for a genuine co-signing pass (field name always a real,
     * caller-fixed value - never conditional/null - since which document to build
     * on top of can no longer be safely resolved here).
     *
     * Deliberately does NOT resolve "is there a prior completed signing" or write
     * any unsigned.pdf content at this point - doing so eagerly, at HTTP-request
     * time, raced the applicant's own auto-dispatched signing (or an earlier
     * co-signing pass) whenever it hadn't finished its pyHanko/TSA round trip yet:
     * the eager check would find "no completed signing exists yet" and silently
     * render a fresh, blank-based PDF, discarding every already-completed
     * signature. That's a real incident that happened in production (leave
     * #2606). SignESignatureRequestPdfJob::resolveCoSigningBasePdf() now resolves
     * this at job-execution time instead, using the job's own retry/backoff to
     * give an in-flight sibling signing real wall-clock time to finish first.
     */
    private function dispatchCoSigningPass(LeaveRequest $leave, User $actor, string $password, string $fieldName, ?array $fieldRectOverride = null): EsignatureSigning
    {
        $token = (string) Str::ulid();
        $dir = "signings/{$token}";

        if ($fieldRectOverride) {
            Storage::disk('esignature')->put("{$dir}/signature_field.json", json_encode($fieldRectOverride));
        }

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $actor->id,
            'field_name' => $fieldName,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => "{$dir}/unsigned.pdf",
        ]);

        SignESignatureRequestPdfJob::dispatch($signing, $password)->onQueue('exports');

        return $signing;
    }

    private function isSuccessfulJsonResponse($response): bool
    {
        if (! $response instanceof JsonResponse) {
            return false;
        }

        return ($response->getData(true)['success'] ?? false) === true;
    }

    /**
     * Generate an Excel file for a leave request using the LEAVE.xlsx template.
     * Saves the file to storage/app/leave/prints and returns it as a download.
     */
    public function generateExcelResponse(LeaveRequest $leave): StreamedResponse
    {
        [$spreadsheet, $officialLabel] = $this->buildFilledLeaveSpreadsheet($leave);
        $employee = $leave->user;

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
     * Builds the LEAVE.xlsx template filled with a leave's data - the exact same
     * cell-by-cell fill this app has always used for the official Excel export
     * (generateExcelResponse() above), extracted so buildEsignaturePdfBytes() can
     * render the identical official form layout as a PDF instead of duplicating
     * this fill logic in a second, hand-built view. Unprotected - protectAllSheets()
     * is an Excel-specific concept the PDF path never applies (it gets a real
     * cryptographic signature instead, a much stronger guarantee).
     *
     * @return array{0: Spreadsheet, 1: string} [filled spreadsheet, executive signatory label]
     */
    private function buildFilledLeaveSpreadsheet(LeaveRequest $leave): array
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
                ->current()
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
            'study / examination leave' => 25,
            '10-day vawc leave' => 27,
            'vawc leave' => 27,
            'vawc' => 27,
            'rehabilitation privilege' => 29,
            'special leave benefits for women' => 31,
            'special leave (gynecological)' => 31, // RA 9710 / CSC MC No. 25, s. 2010 — same official leave as the row above
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
                    $sheet->setCellValue('C40', 'Wellness Leave');
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

        return [$spreadsheet, $officialLabel ?? ''];
    }

    /**
     * Stamps the real official CS Form 6 PDF (storage/app/templates/LEAVE.pdf,
     * supplied directly by the office - a genuine 2-page export of the actual
     * form, page 2 a static reference image, no AcroForm fields) with a
     * leave's data via FPDI, returning PDF bytes - the source document
     * SignESignatureRequestPdfJob signs. A prior version rendered the filled
     * LEAVE.xlsx as HTML via Dompdf instead; that approach is gone now that a
     * real PDF exists to stamp onto directly, which is unambiguously more
     * faithful to the actual form than any re-rendering could be.
     *
     * Reuses buildFilledLeaveSpreadsheet()'s cell-by-cell business logic
     * unchanged (balance snapshots, leave-type resolution, signatory lookups)
     * by reading the values back out of the cells it already computed, rather
     * than recomputing anything here - one source of truth for the data, two
     * ways of placing it on a page.
     *
     * Coordinates live in storage/app/templates/leave_mapping.php, measured
     * directly against this exact PDF's own content stream (not guessed) -
     * see that file's docblock and the plan for the extraction method. Uses
     * FPDI's 'pt' unit explicitly (FPDF defaults to millimeters, which would
     * silently misplace every point-based coordinate in that mapping file by
     * roughly a factor of ~2.83).
     */
    public function buildEsignaturePdfBytes(LeaveRequest $leave): string
    {
        [$spreadsheet] = $this->buildFilledLeaveSpreadsheet($leave);
        $sheet = $spreadsheet->getSheet(0);
        $cell = fn (string $ref): string => trim((string) ($sheet->getCell($ref)->getValue() ?? ''));

        $templatePath = storage_path('app/templates/LEAVE.pdf');
        if (! file_exists($templatePath)) {
            abort(500, 'Leave PDF template not found.');
        }

        $mappingFile = storage_path('app/templates/leave_mapping.php');
        $mapping = file_exists($mappingFile) ? include $mappingFile : [];

        $pdf = new Fpdi('P', 'pt');
        $pageCount = $pdf->setSourceFile($templatePath);
        $tplId = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tplId);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tplId);

        // leave_mapping.php's coordinates are true PDF points measured directly from the
        // page's own content stream (bottom-left origin, matching PDF's real convention).
        // FPDF's own SetXY() uses the opposite, GUI-style top-left origin - confirmed
        // empirically (a value stamped at the mapping's raw y landed nowhere near its
        // intended label). This is the one place that inversion happens, so the mapping
        // file itself can stay in the more natural, directly-measured coordinate space.
        $pageHeight = $size['height'];

        // FPDF's Write()/Cell() position text by (roughly) the TOP of the line box, not by
        // the glyph baseline - every $write() coordinate in leave_mapping.php is measured
        // against the baseline (real Tm operators in the source content stream), so writing
        // at the raw $toFpdfY($y) silently rendered every $write()-driven field low by a
        // fixed, font-size-dependent amount. Confirmed empirically (not guessed) by comparing
        // requested vs actually rendered glyph baselines (PyMuPDF rawdict text extraction)
        // across four different fields/font sizes - it matches FPDF's own internal Cell()
        // formula exactly every time: offset = 0.5*$h + 0.3*fontSize, where $h is the
        // line-height argument passed to Write() (hardcoded to 5 everywhere in this method).
        //   size 9 -> measured 5.20pt low  | formula: 0.5*5 + 0.3*9 = 5.20
        //   size 8 -> measured 4.90pt low  | formula: 0.5*5 + 0.3*8 = 4.90
        //   size 7 -> measured 4.60pt low  | formula: 0.5*5 + 0.3*7 = 4.60
        // $toFpdfBaselineY bakes this compensation in once, here, so every $write() call below
        // (and every $write()-driven entry in leave_mapping.php) can keep meaning exactly what
        // its own comments already claim - "this is the real measured baseline" - without each
        // field having to carry its own hand-tuned fudge factor.
        //
        // Deliberately NOT applied to $mark() below: a mark's stored (x,y) is a checkbox
        // CENTER point (leave_mapping.php's own docblock: "its true center is consistently
        // ~2.3pt above the row label's own baseline"), not a text baseline - a different
        // semantic that this baseline formula doesn't fit. $mark()'s existing plain
        // top-down flip was already correctly calibrated for that (an "X" glyph's own
        // baseline naturally sits a bit below its visual center, close to what the old,
        // uncompensated conversion produced) - applying this compensation there too was
        // tried and reverted after it pushed every checkbox/purpose mark's "X" visibly
        // above the box it's meant to sit inside.
        $lineHeight = 5;
        $toFpdfBaselineY = fn (float $y, float $fontSize): float => $pageHeight - ($y + 0.5 * $lineHeight + 0.3 * $fontSize);
        $toFpdfY = fn (float $y): float => $pageHeight - $y;

        $write = function (string $key, string $text) use ($pdf, $mapping, $toFpdfBaselineY): void {
            $cfg = $mapping[$key] ?? null;
            if (! $cfg || ! isset($cfg['x'], $cfg['y']) || $text === '') {
                return;
            }
            $fontSize = $cfg['size'] ?? 9;
            $pdf->SetFont($cfg['font'] ?? 'Arial', ($cfg['bold'] ?? false) ? 'B' : '', $fontSize);
            $pdf->SetXY($cfg['x'], $toFpdfBaselineY($cfg['y'], $fontSize));
            $pdf->Write(5, $text);
        };

        $mark = function (?array $xy) use ($pdf, $toFpdfY): void {
            if (! $xy) {
                return;
            }
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetXY($xy[0], $toFpdfY($xy[1]));
            $pdf->Write(5, 'X');
        };

        $write('full_name', $cell('E5'));
        $write('department', $cell('B5'));
        $write('date_filed', $cell('D6'));
        $write('position', $cell('F6'));
        $write('salary', $cell('K6'));

        // Leave-type checkmarks - mirrors buildFilledLeaveSpreadsheet()'s own row map, but
        // reads which B{row} cells it actually marked rather than re-deriving from leave_type.
        $leaveTypeRows = [
            11 => 'vacation leave', 13 => 'mandatory/forced leave', 15 => 'sick leave',
            17 => 'maternity leave', 19 => 'paternity leave', 21 => 'special privilege leave',
            23 => 'solo parent leave', 25 => 'study leave', 27 => '10-day vawc leave',
            29 => 'rehabilitation privilege', 31 => 'special leave benefits for women',
            33 => 'special emergency (calamity) leave', 35 => 'adoption leave', 39 => 'others',
        ];
        $leaveTypeCoords = $mapping['leave_type_coords'] ?? [];
        foreach ($leaveTypeRows as $row => $key) {
            if ($cell("B{$row}") !== '') {
                $mark($leaveTypeCoords[$key] ?? null);
            }
        }
        $othersText = $cell('C40');
        if ($othersText !== '' && ($area = $mapping['others_area'] ?? null)) {
            $pdf->SetFont('Arial', '', 9);
            // MultiCell's own $y is the top of the wrapped text block, not a single glyph
            // baseline like Write() - the $toFpdfBaselineY compensation above doesn't apply
            // here, a plain top-down flip is correct for this call.
            $pdf->SetXY($area['x'], $pageHeight - $area['y']);
            $pdf->MultiCell($area['w'], $area['h'] ?? 5, $othersText);
        }

        // 6.B Details of Leave
        $purposeMarks = $mapping['purpose_marks'] ?? [];
        if ($cell('H13') !== '') {
            $mark($purposeMarks['within_the_philippines'] ?? null);
        }
        if ($cell('H15') !== '') {
            $mark($purposeMarks['abroad'] ?? null);
        }
        if ($cell('H19') !== '') {
            $mark($purposeMarks['in_hospital'] ?? null);
            $write('specify_illness_in_hospital', $cell('K19'));
        }
        if ($cell('H21') !== '') {
            $mark($purposeMarks['out_patient'] ?? null);
            $write('specify_illness_out_patient', $cell('K21'));
        }

        // 6.C / 6.D
        $write('total_days', $cell('C44'));
        $write('period', $cell('C48'));
        if ($cell('H45') !== '') {
            $mark($mapping['commutation_not_requested'] ?? null);
        }

        // 7.A Certification of Leave Credits
        $write('approved_at', $cell('D53'));
        $write('vl_total_earned', $cell('D56'));
        $write('sl_total_earned', $cell('E56'));
        $write('vl_requested', $cell('D57'));
        $write('sl_requested', $cell('E57'));
        $write('vl_balance', $cell('D58'));
        $write('sl_balance', $cell('E58'));

        $status = strtolower($leave->status ?? '');

        // 7.B Recommendation
        if ($cell('H53') !== '') {
            $mark($mapping['recommend_approval'] ?? null);
        }
        if ($cell('H55') !== '') {
            $mark($mapping['recommend_disapproval'] ?? null);
        }
        // Gated on the same rejected check buildFilledLeaveSpreadsheet() uses to set I56
        // in the first place (line ~1556-1560 above) - I56 is only ever explicitly written
        // for a rejected leave; for every other status it still holds the LEAVE.xlsx
        // template's own raw unfilled placeholder text (a run of underscores), which,
        // written unconditionally, stamped directly on top of this PDF's own static
        // "For disapproval due to ________________________" line - visually doubling and
        // extending it past the box's own right border. Confirmed via a real render.
        if ($status === 'rejected') {
            $write('disapproval_reason', $cell('I56'));
        }

        // 7.C / 7.D - sourced from the leave's own paid_days/lwop_days directly, not from
        // C62/C63's already-underscore-substituted sentence (that phrasing is specific to
        // the Excel template's own placeholder text, not this PDF's separate printed caption).
        //
        // Deliberately NOT gated on status === 'approved' (changed 2026-08-21): this PDF
        // is only ever actually rendered once, at filing time (maybeStartEsignaturePrint()),
        // while the leave is still 'pending' - a later Department Head/Administrative
        // Officer co-sign (approveLeaveWithEsignature()) reuses those exact already-signed
        // bytes unchanged rather than re-rendering, since pyHanko's own incremental-update
        // signing is the only safe way to layer content onto an already-signed PDF (FPDI is
        // not - confirmed empirically: running an already-signed PDF back through FPDI
        // strips its signature/AcroForm/DSS entirely, since FPDI fully rebuilds the file
        // rather than appending to it). So the old status==='approved' gate meant paid_days/
        // lwop_days could never actually appear on a real leave's PDF - the one time this
        // method runs, approval hadn't happened yet. paid_days/lwop_days are already fully
        // computed and stored on the leave_requests row at filing (LeaveRequestController::
        // store()), independent of approval, so showing them here is a pre-approval preview
        // of the same split the DH/AO will actually approve, not a live "approved" claim -
        // only suppressed once a rejection has actually happened, matching the mutually
        // exclusive 7.C/7.D printed sections (a leave can't be both).
        if ($status === 'rejected') {
            $write('disapproved_due_to', (string) ($leave->rejection_notes ?? ''));
        } else {
            if (($leave->paid_days ?? 0) > 0) {
                $write('paid_days', $this->formatBalance($leave->paid_days));
            }
            if (($leave->lwop_days ?? 0) > 0) {
                $write('lwop_days', $this->formatBalance($leave->lwop_days));
            }
        }

        // Signatories
        $write('department_head', $cell('I59'));
        $write('signatory_name', $cell('D66'));
        $write('signatory_designation', $cell('D67'));

        $siteSettings = Setting::first();
        if ($siteSettings) {
            if (! empty($siteSettings->hr_manager_name)) {
                $write('hr_manager_name', $siteSettings->hr_manager_name);
            }
            $write('hr_manager_designation', $siteSettings->hr_manager_designation ?? 'OIC-CHRMD');
        }

        // Remaining page(s) - just the template's own static content (page 2 is a plain
        // reference image), added as-is with no stamping.
        for ($pageNo = 2; $pageNo <= $pageCount; $pageNo++) {
            $tpl = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }

        $spreadsheet->disconnectWorksheets();

        return $pdf->Output('S');
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
