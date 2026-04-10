<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use setasign\Fpdi\Fpdi;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\LeaveRequestStatusNotification;
use Carbon\Carbon;

/**
 * Service responsible for leave request related business logic such as
 * permission checks and PDF generation for leave forms.
 */
class LeaveRequestService
{
    protected DepartmentService $departmentService;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    /**
     * Determine if the given user may print the provided leave request.
     *
     * @param  \App\Models\LeaveRequest  $leave
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function canPrint(LeaveRequest $leave, User $user): bool
    {
        if ($leave->user_id === $user->id) {
            return true;
        }

        $dept = $this->departmentService->resolveDepartmentForUser($user);
        if ($dept && $leave->user && ($leave->user->Dept_id == $dept->Dept_id)) {
            return true;
        }

        try {
            $role = strtolower(trim((string)$user->access_level));
            if ($role === 'administrative officer') {
                return true;
            }
        } catch (\Exception $e) {
            // ignore and continue
        }

        return false;
    }

    /**
     * Generate PDF content for a leave request using the existing template mapping.
     * Returns a Symfony response containing PDF bytes on success.
     *
     * @param  \App\Models\LeaveRequest  $leave
     * @return \Illuminate\Http\Response
     */
    public function generatePdfResponse(LeaveRequest $leave): Response
    {
        $employee = $leave->user;

        $fullNameParts = [];
        if (!empty($employee->first_name)) $fullNameParts[] = $employee->first_name;
        if (!empty($employee->middle_name)) $fullNameParts[] = $employee->middle_name;
        if (!empty($employee->last_name)) $fullNameParts[] = $employee->last_name;
        if (empty($fullNameParts) && !empty($employee->name)) $fullNameParts[] = $employee->name;
        $fullName = implode(' ', $fullNameParts);

        $start = $leave->start_date ? \Illuminate\Support\Carbon::parse($leave->start_date)->format('M d, Y') : '';
        $end = $leave->end_date ? \Illuminate\Support\Carbon::parse($leave->end_date)->format('M d, Y') : '';
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
        if (!file_exists($templatePath)) {
            // fallback: render existing blade fallback view
            $view = view('employee.leave-print', ['leaves' => collect([$leave])]);
            return response($view->render(), 200);
        }

        $mappingFile = storage_path('app/templates/leave_mapping.php');
        $mapping = [];
        if (file_exists($mappingFile)) {
            $mapping = include $mappingFile;
        }

        $pdf = new Fpdi();
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
                $pdf->Write(5, (string)$text);
                return true;
            }
            return false;
        };

        $write('full_name', $fullName, ['bold' => true]);
        $write('department', $departmentName, ['bold' => true]);

        // Department head name
        $deptHeadName = '';
        try {
            if (isset($dept) && $dept && !empty($dept->EmpNo)) {
                $headUser = User::where('EmpNo', $dept->EmpNo)->first();
                if ($headUser) {
                    $parts = [];
                    if (!empty($headUser->first_name)) $parts[] = $headUser->first_name;
                    if (!empty($headUser->middle_name)) $parts[] = $headUser->middle_name;
                    if (!empty($headUser->last_name)) $parts[] = $headUser->last_name;
                    if (empty($parts) && !empty($headUser->name)) $parts[] = $headUser->name;
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
        $write('total_days', (string)($leave->total_days ?? ''));
        $write('approved_at', $approvedAt, ['bold' => true]);
        $write('vl', (string)($leave->balance_vacation_leave ?? ''));
        $write('sl', (string)($leave->balance_sick_leave ?? ''));

        $put('abroad_place', $leave->details_location_specify ?? '');

        // sick treatment handling and other mapping adjustments follow same logic
        $sickTreatment = strtolower(trim((string)($leave->details_sick_treatment ?? '')));
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

        $empLB = $employee->leaveBalance ?? null;
        $displayTotalEarnedVL = $leave->balance_vacation_leave ?? ($empLB->VL ?? 0);
        $displayTotalEarnedSL = $leave->balance_sick_leave ?? ($empLB->SL ?? 0);

        $lt = strtolower((string)($leave->leave_type ?? ''));
        $displayRequestedVL = (stripos($lt, 'vacation') !== false || stripos($lt, 'vl') !== false) ? ($leave->paid_days ?? 0) : 0;
        $displayRequestedSL = (stripos($lt, 'sick') !== false || stripos($lt, 'sl') !== false) ? ($leave->paid_days ?? 0) : 0;

        $displayBalanceVL = $empLB ? ($empLB->VL ?? $displayTotalEarnedVL) : $displayTotalEarnedVL;
        $displayBalanceSL = $empLB ? ($empLB->SL ?? $displayTotalEarnedSL) : $displayTotalEarnedSL;

        $PaidDays = $leave->paid_days ?? 0;
        $LWOPDays = $leave->lwop_days ?? 0;

        $formatUpTo3 = function ($val) {
            if (!is_numeric($val)) return (string)$val;
            $s = (string)$val;
            $neg = false;
            if (substr($s,0,1) === '-') { $neg = true; $s = substr($s,1); }
            if (strpos($s, '.') === false) {
                return $neg ? ('-' . $s) : $s;
            }
            list($int, $dec) = explode('.', $s, 2);
            $dec = substr($dec . str_repeat('0', 3), 0, 3);
            $dec = rtrim($dec, '0');
            if ($dec === '') return $neg ? ('-' . $int) : $int;
            return ($neg ? '-' : '') . $int . '.' . $dec;
        };

        $m = $mapping;

        if (! $put('vl_total_earned', $formatUpTo3($displayTotalEarnedVL))) {
            $pdf->SetXY(60, 204); $pdf->Write(5, $formatUpTo3($displayTotalEarnedVL));
        }
        if (! $put('vl_requested', $formatUpTo3($displayRequestedVL))) {
            $pdf->SetXY(60, 208); $pdf->Write(5, $formatUpTo3($displayRequestedVL));
        }
        if (! $put('vl_balance', $formatUpTo3($displayBalanceVL))) {
            $pdf->SetXY(60, 212); $pdf->Write(5, $formatUpTo3($displayBalanceVL));
        }

        if (! $put('sl_total_earned', $formatUpTo3($displayTotalEarnedSL))) {
            $pdf->SetXY(87, 204); $pdf->Write(5, $formatUpTo3($displayTotalEarnedSL));
        }
        if (! $put('sl_requested', $formatUpTo3($displayRequestedSL))) {
            $pdf->SetXY(87, 208); $pdf->Write(5, $formatUpTo3($displayRequestedSL));
        }
        if (! $put('sl_balance', $formatUpTo3($displayBalanceSL))) {
            $pdf->SetXY(87, 212); $pdf->Write(5, $formatUpTo3($displayBalanceSL));
        }

        if (! $put('paid_days', $formatUpTo3($PaidDays))) {
            $pdf->SetXY(23, 236); $pdf->Write(5, $formatUpTo3($PaidDays));
        }
        if (! $put('lwop_days', $formatUpTo3($LWOPDays))) {
            $pdf->SetXY(23, 239); $pdf->Write(5, $formatUpTo3($LWOPDays));
        }

        $selectedKeys = array_filter(array_map('trim', explode(',', (string)$leave->leave_type)));
        $leaveTypeCoords = $m['leave_type_coords'] ?? [];
        $normCoords = [];
        foreach ($leaveTypeCoords as $k => $v) {
            $normCoords[strtolower(trim($k))] = $v;
        }
        foreach ($selectedKeys as $key) {
            $normKey = strtolower(trim($key));
            if (!isset($normCoords[$normKey])) continue;
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
            $mark = $pm['within_the_philippines'] ?? [115,81];
            $markX($mark[0], $mark[1]);
        }
        if (strpos($purposeLower, 'abroad') !== false) {
            $mark = $pm['abroad'] ?? [115,86];
            $markX($mark[0], $mark[1]);
        }
        if (strpos($purposeLower, 'in hospital') !== false) {
            $mark = $pm['in_hospital'] ?? [115,96];
            $markX($mark[0], $mark[1]);
        }
        if (strpos($purposeLower, 'out patient') !== false || strpos($purposeLower, 'outpatient') !== false) {
            $mark = $pm['out_patient'] ?? [115,101];
            $markX($mark[0], $mark[1]);
        }

        $treatment = strtolower(trim((string)($leave->details_sick_treatment ?? '')));
        if ($treatment !== '') {
            if (strpos($treatment, 'hospital') !== false || $treatment === 'in_hospital' || $treatment === 'in-hospital' || $treatment === 'hospital') {
                $coords = $pm['in_hospital'] ?? [115,96];
                $markX($coords[0], $coords[1]);
            } elseif (strpos($treatment, 'out') !== false || $treatment === 'out_patient' || $treatment === 'outpatient') {
                $coords = $pm['out_patient'] ?? [115,101];
                $markX($coords[0], $coords[1]);
            }
        }

        if (preg_match('/special\s+leave\s+benefits\s+for\s+women\s*:\s*([^|]+)/i', $purposeText, $wm)) {
            $womenIllness = trim((string)$wm[1]);
            if ($womenIllness !== '') {
                $pdf->SetFont('Arial', '', 9);
                $coords = $pm['women_illness'] ?? [115,122];
                $pdf->SetXY($coords[0], $coords[1]);
                $pdf->MultiCell(80, 4, $womenIllness);
            }
        }

        if (strpos($purposeLower, "completion of master's degree") !== false || strpos($purposeLower, 'completion of masters degree') !== false) {
            $coords = $pm['study_completion'] ?? [115,132];
            $markX($coords[0], $coords[1]);
        }
        if (strpos($purposeLower, 'bar/board examination review') !== false || strpos($purposeLower, 'bar') !== false) {
            $coords = $pm['bar_review'] ?? [115,137];
            $markX($coords[0], $coords[1]);
        }

        if (strpos($purposeLower, 'monetization of leave credits') !== false || strpos($purposeLower, 'monetization') !== false) {
            $coords = $pm['monetization'] ?? [115,148];
            $markX($coords[0], $coords[1]);
        }
        if (strpos($purposeLower, 'terminal leave') !== false) {
            $coords = $pm['terminal_leave'] ?? [115,152];
            $markX($coords[0], $coords[1]);
        }

        $isExplicitOthers = stripos((string)$leave->leave_type, 'Others') !== false;
        if ($isExplicitOthers && in_array('Others', $selectedKeys)) {
            $area = $m['others_area'] ?? null;
            $reason = (string)($leave->reason ?? '');
            $pdf->SetFont('Arial','',11);
            if ($area) {
                $pdf->SetXY($area['x'], $area['y']);
                $pdf->MultiCell($area['w'], $area['h'], $reason);
            } else {
                $pdf->SetXY(23,152);
                $pdf->MultiCell(120,5,$reason);
            }
        }

        // --- Signatory logic ---
        // Determine the applicant's department hierarchy once (reuse $dept if already set)
        $deptId       = isset($dept) ? (int) ($dept->Dept_id       ?? 0) : 0;
        $parentDeptId = isset($dept) ? (int) ($dept->parent_dept_id ?? 0) : 0;

        // Resolve the correct executive signatory based on department chain:
        //   Dept_id == 1 OR parent_dept_id == 1  → Mayor's office / child of Mayor
        //   Dept_id == 2 OR parent_dept_id == 2  → Vice Mayor's office / child of Vice Mayor
        $siteSettings = Setting::first();
        $signatoryName        = '';
        $signatoryDesignation = '';

        if ($siteSettings) {
            if ($deptId === 1 || $parentDeptId === 1) {
                $signatoryName        = $siteSettings->mayor_name        ?? '';
                $signatoryDesignation = $siteSettings->mayor_designation  ?? '';
            } elseif ($deptId === 2 || $parentDeptId === 2) {
                $signatoryName        = $siteSettings->vice_mayor_name        ?? '';
                $signatoryDesignation = $siteSettings->vice_mayor_designation  ?? '';
            } else {
                // Default to Mayor for all other departments
                $signatoryName        = $siteSettings->mayor_name        ?? '';
                $signatoryDesignation = $siteSettings->mayor_designation  ?? '';
            }

            if ($signatoryName !== '') {
                $write('signatory_name', $signatoryName);
            }
            if ($signatoryDesignation !== '') {
                $write('signatory_designation', $signatoryDesignation);
            }

            // HR Manager is always written
            if (!empty($siteSettings->hr_manager_name)) {
                $write('hr_manager_name', $siteSettings->hr_manager_name);
            }
            if (!empty($siteSettings->hr_manager_designation)) {
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
            ->header('Content-Disposition', 'inline; filename="leave-'. $leave->id .'.pdf"');
    }

    /**
     * Approve a leave request and perform balance deductions where applicable.
     * Keeps the same response shapes as the controller version.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function approveLeave($request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);

        if ($leave->status === 'approved') {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Leave already approved.']);
            }
            return redirect()->back()->with('success', 'Leave already approved.');
        }

        $user = $leave->user;
        $leaveBalance = $user->leaveBalance;
        if (!$leaveBalance) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'No leave balance record found for this user.'], 422);
            }
            return redirect()->back()->with('error', 'No leave balance record found for this user.');
        }

        $column = null;
        $label = strtolower($leave->leave_type ?? '');
        if (str_contains($label, 'vacation') || str_contains($label, 'vl')) $column = 'VL';
        elseif (str_contains($label, 'sick') || str_contains($label, 'sl')) $column = 'SL';
        elseif (str_contains($label, 'wellness') || str_contains($label, 'wlns')) $column = 'WLNS';
        elseif (str_contains($label, 'solo') || str_contains($label, 'solo parent')) $column = 'SP';
        elseif (str_contains($label, 'special') || str_contains($label, 'privilege') || str_contains($label, 'spl')) $column = 'SPL';
        elseif (str_contains($label, 'cto')) $column = 'CTO';

        if (!$column) {
            $parts = array_map('trim', explode(',', $leave->leave_type));
            foreach ($parts as $p) {
                $pl = strtolower($p);
                if (str_contains($pl, 'vacation') || str_contains($pl, 'vl')) { $column = 'VL'; break; }
                if (str_contains($pl, 'sick') || str_contains($pl, 'sl')) { $column = 'SL'; break; }
                if (str_contains($pl, 'wellness') || str_contains($pl, 'wlns')) { $column = 'WLNS'; break; }
                if (str_contains($pl, 'solo') || str_contains($pl, 'solo parent')) { $column = 'SP'; break; }
                if (str_contains($pl, 'special') || str_contains($pl, 'privilege') || str_contains($pl, 'spl')) { $column = 'SPL'; break; }
                if (str_contains($pl, 'cto')) { $column = 'CTO'; break; }
            }
        }

        $toDeduct = floatval($leave->paid_days ?? 0);
        if ($toDeduct <= 0) {
            $leave->status = 'approved';
            $leave->save();
            // notify employee about approval
            try {
                $employee = $leave->user;
                if ($employee && !empty($employee->Dept_id)) {
                    $dept = Department::find($employee->Dept_id);
                    if ($dept) $employee->department_name = $dept->Dept_name ?? null;
                }
                $formatted = [
                    'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                    'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                    'end'   => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                ];
                $balances = [
                    'VL'   => $leaveBalance->VL   ?? 0,
                    'SL'   => $leaveBalance->SL   ?? 0,
                    'WLNS' => $leaveBalance->WLNS ?? 0,
                    'SP'   => $leaveBalance->SP   ?? 0,
                    'SPL'  => $leaveBalance->SPL  ?? 0,
                    'CTO'  => $leaveBalance->CTO  ?? 0,
                ];
                if ($employee) {
                    $email = $employee->email ?? null;
                    Log::info('Leave approval email attempt', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null, 'email' => $email]);
                    if (!empty($email)) {
                        Mail::to($email)->queue(new LeaveRequestStatusNotification($employee, $leave, $formatted, 'approved', null, $balances));
                        Log::info('Leave approval email queued', ['leave_id' => $leave->id, 'email' => $email]);
                    } else {
                        Log::warning('Leave approval email not sent: employee has no email', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error sending leave approval email', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
            }
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Leave approved.']);
            }
            return redirect()->back()->with('success', 'Leave approved.');
        }

        if (!$column) {
            $leave->status = 'approved';
            $leaveBalance->refresh();
            $leave->balance_vacation_leave = $leaveBalance->VL;
            $leave->balance_sick_leave = $leaveBalance->SL;
            $leave->balance_wellness_leave = $leaveBalance->WLNS;
            $leave->balance_solo_parent_leave = $leaveBalance->SP;
            $leave->balance_special_leave_privilege = $leaveBalance->SPL;
            $leave->save();
            // notify employee about approval
            try {
                $employee = $leave->user;
                if ($employee && !empty($employee->Dept_id)) {
                    $dept = Department::find($employee->Dept_id);
                    if ($dept) $employee->department_name = $dept->Dept_name ?? null;
                }
                $formatted = [
                    'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                    'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                    'end'   => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                ];
                $balances = [
                    'VL'   => $leaveBalance->VL   ?? 0,
                    'SL'   => $leaveBalance->SL   ?? 0,
                    'WLNS' => $leaveBalance->WLNS ?? 0,
                    'SP'   => $leaveBalance->SP   ?? 0,
                    'SPL'  => $leaveBalance->SPL  ?? 0,
                    'CTO'  => $leaveBalance->CTO  ?? 0,
                ];
                if ($employee) {
                    $email = $employee->email ?? null;
                    Log::info('Leave approval email attempt', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null, 'email' => $email]);
                    if (!empty($email)) {
                        Mail::to($email)->queue(new LeaveRequestStatusNotification($employee, $leave, $formatted, 'approved', null, $balances));
                        Log::info('Leave approval email queued', ['leave_id' => $leave->id, 'email' => $email]);
                    } else {
                        Log::warning('Leave approval email not sent: employee has no email', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error sending leave approval email', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
            }
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Leave approved.']);
            }
            return redirect()->back()->with('success', 'Leave approved.');
        }

        try {
            DB::transaction(function () use ($leaveBalance, $column, $toDeduct, $leave) {
                if ($column === 'SL') {
                    $dedFromSL = min($leaveBalance->SL, $toDeduct);
                    $leaveBalance->SL -= $dedFromSL;
                    $remaining = $toDeduct - $dedFromSL;
                    if ($remaining > 0) {
                        $dedFromVL = min($leaveBalance->VL, $remaining);
                        $leaveBalance->VL -= $dedFromVL;
                        $remaining -= $dedFromVL;
                    }
                    if (isset($remaining) && $remaining > 0) {
                        throw new \Exception('Insufficient combined SL/VL balance.');
                    }
                } else {
                    if ($leaveBalance->$column < $toDeduct) {
                        throw new \Exception('Insufficient leave balance for ' . $column . '.');
                    }
                    $leaveBalance->$column -= $toDeduct;
                }

                $leaveBalance->save();

                $leave->status = 'approved';
                $leave->balance_vacation_leave = $leaveBalance->VL;
                $leave->balance_sick_leave = $leaveBalance->SL;
                $leave->balance_wellness_leave = $leaveBalance->WLNS;
                $leave->balance_solo_parent_leave = $leaveBalance->SP;
                $leave->balance_special_leave_privilege = $leaveBalance->SPL;
                $leave->save();
            });
        } catch (\Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return redirect()->back()->with('error', $e->getMessage());
        }

        // notify employee about approval after transaction (runs for both Ajax and non-Ajax)
        try {
            $employee = $leave->user;
            if ($employee && !empty($employee->Dept_id)) {
                $dept = Department::find($employee->Dept_id);
                if ($dept) $employee->department_name = $dept->Dept_name ?? null;
            }
            $formatted = [
                'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                'end'   => Carbon::parse($leave->end_date)->format('l, F j, Y'),
            ];
            $balances = [
                'VL'   => $leaveBalance->VL   ?? 0,
                'SL'   => $leaveBalance->SL   ?? 0,
                'WLNS' => $leaveBalance->WLNS ?? 0,
                'SP'   => $leaveBalance->SP   ?? 0,
                'SPL'  => $leaveBalance->SPL  ?? 0,
                'CTO'  => $leaveBalance->CTO  ?? 0,
            ];
            if ($employee) {
                $email = $employee->email ?? null;
                Log::info('Leave approval email attempt', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null, 'email' => $email]);
                if (!empty($email)) {
                    Mail::to($email)->queue(new LeaveRequestStatusNotification($employee, $leave, $formatted, 'approved', null, $balances));
                    Log::info('Leave approval email queued', ['leave_id' => $leave->id, 'email' => $email]);
                } else {
                    Log::warning('Leave approval email not sent: employee has no email', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error sending leave approval email', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
        }
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Leave approved and balance updated.']);
        }
        return redirect()->back()->with('success', 'Leave approved and balance updated.');
    }
}
