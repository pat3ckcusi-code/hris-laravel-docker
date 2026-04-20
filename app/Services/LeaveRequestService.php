<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\Setting;
use App\Models\User;
use setasign\Fpdi\Fpdi;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\LeaveRequestStatusNotification;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Generate an Excel file for a leave request using the LEAVE.xlsx template.
     * Saves the file to storage/app/leave/prints and returns it as a download.
     */
    public function generateExcelResponse(LeaveRequest $leave): StreamedResponse
    {
        $templatePath = storage_path('app/templates/LEAVE.xlsx');
        if (!file_exists($templatePath)) {
            abort(500, 'Leave Excel template not found.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheet(0);

        $employee = $leave->user;
        $checkMark = '✓';

        // --- 1. Header / Personal Info ---
        $lastName  = $employee->last_name ?? '';
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
                $matrix = SalaryMatrix::where('sg', $plantilla->salary_grade)
                    ->where('step', $plantilla->step)
                    ->orderByDesc('year')
                    ->first();
                $salary = $matrix ? number_format($matrix->amount, 2) : '';
            }
        }
        $sheet->setCellValue('K6', $salary);

        // --- 2. Leave Type Checkboxes (Column B) ---
        $leaveTypeRowMap = [
            'vacation leave'                    => 11,
            'vl'                                => 11,
            'mandatory/forced leave'            => 13,
            'mandatory leave'                   => 13,
            'forced leave'                      => 13,
            'sick leave'                        => 15,
            'sl'                                => 15,
            'maternity leave'                   => 17,
            'paternity leave'                   => 19,
            'special privilege leave'           => 21,
            'spl'                               => 21,
            'solo parent leave'                 => 23,
            'study leave'                       => 25,
            '10-day vawc leave'                 => 27,
            'vawc leave'                        => 27,
            'vawc'                              => 27,
            'rehabilitation privilege'          => 29,
            'special leave benefits for women'  => 31,
            'special emergency (calamity) leave'=> 33,
            'calamity leave'                    => 33,
            'adoption leave'                    => 35,
            'wellness leave'                    => 39,
            'wlns'                              => 39,
            'others'                            => 39,
        ];

        $selectedTypes = array_filter(array_map('trim', explode(',', (string)$leave->leave_type)));
        $matchedOthers = false;
        foreach ($selectedTypes as $type) {
            $normalized = strtolower(trim($type));
            $row = $leaveTypeRowMap[$normalized] ?? null;
            if ($row) {
                $sheet->setCellValue("B{$row}", $checkMark);
                if ($row === 39) {
                    $matchedOthers = true;
                }
            } else {
                // Unknown leave type → mark as Others
                $sheet->setCellValue('B39', $checkMark);
                $sheet->setCellValue('D39', $type);
                $matchedOthers = true;
            }
        }

        if ($matchedOthers && !empty($leave->reason)) {
            $currentOthers = $sheet->getCell('D39')->getValue();
            if (empty($currentOthers)) {
                $sheet->setCellValue('D39', $leave->reason);
            }
        }

        // --- 3. Details of Leave (right side) ---
        $location = strtolower(trim((string)($leave->details_location ?? '')));
        $locationSpecify = $leave->details_location_specify ?? '';

        if (strpos($location, 'within') !== false || $location === 'within_the_philippines') {
            $sheet->setCellValue('H13', $checkMark);
            if ($locationSpecify) {
                $sheet->setCellValue('J13', $locationSpecify);
            }
        }
        if (strpos($location, 'abroad') !== false) {
            $sheet->setCellValue('H15', $checkMark);
            if ($locationSpecify) {
                $sheet->setCellValue('J15', $locationSpecify);
            }
        }

        // Sick leave details
        $sickTreatment = strtolower(trim((string)($leave->details_sick_treatment ?? '')));
        $sickIllness = $leave->details_sick_illness ?? '';
        if (strpos($sickTreatment, 'hospital') !== false || $sickTreatment === 'in_hospital') {
            $sheet->setCellValue('H19', $checkMark);
            if ($sickIllness) {
                $sheet->setCellValue('J19', $sickIllness);
            }
        }
        if (strpos($sickTreatment, 'out') !== false || $sickTreatment === 'out_patient' || $sickTreatment === 'outpatient') {
            $sheet->setCellValue('H21', $checkMark);
            if ($sickIllness) {
                $sheet->setCellValue('J21', $sickIllness);
            }
        }

        // --- 4. Number of Working Days & Inclusive Dates ---
        $sheet->setCellValue('C44', $leave->total_days ?? '');

        $start = $leave->start_date ? Carbon::parse($leave->start_date)->format('m/d/Y') : '';
        $end   = $leave->end_date   ? Carbon::parse($leave->end_date)->format('m/d/Y') : '';
        $inclusiveDates = ($start && $end) ? "{$start} - {$end}" : ($start ?: $end);
        $sheet->setCellValue('C48', $inclusiveDates);

        // Commutation: Not Requested by default
        $sheet->setCellValue('H45', $checkMark);

        // --- 5. Leave Credit Certification (Section 7.A) ---
        $empLB = $employee->leaveBalance ?? null;
        $vlEarned = $leave->balance_vacation_leave ?? ($empLB->VL ?? 0);
        $slEarned = $leave->balance_sick_leave ?? ($empLB->SL ?? 0);

        $lt = strtolower((string)($leave->leave_type ?? ''));
        $vlRequested = (stripos($lt, 'vacation') !== false || stripos($lt, 'vl') !== false) ? ($leave->paid_days ?? 0) : 0;
        $slRequested = (stripos($lt, 'sick') !== false || stripos($lt, 'sl') !== false) ? ($leave->paid_days ?? 0) : 0;

        $vlBalance = $empLB ? ($empLB->VL ?? $vlEarned) : $vlEarned;
        $slBalance = $empLB ? ($empLB->SL ?? $slEarned) : $slEarned;

        // As of date
        $sheet->setCellValue('D53', $dateFiled);

        $sheet->setCellValue('D56', $this->formatBalance($vlEarned));
        $sheet->setCellValue('E56', $this->formatBalance($slEarned));
        $sheet->setCellValue('D57', $this->formatBalance($vlRequested));
        $sheet->setCellValue('E57', $this->formatBalance($slRequested));
        $sheet->setCellValue('D58', $this->formatBalance($vlBalance));
        $sheet->setCellValue('E58', $this->formatBalance($slBalance));

        // HR Manager name
        $siteSettings = Setting::first();
        if ($siteSettings && !empty($siteSettings->hr_manager_name)) {
            $sheet->setCellValue('C60', $siteSettings->hr_manager_name);
        }

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
                $sheet->setCellValue('B62', $this->formatBalance($paidDays));
            }
            if ($lwopDays > 0) {
                $sheet->setCellValue('B63', $this->formatBalance($lwopDays));
            }
        } elseif ($status === 'rejected') {
            $sheet->setCellValue('I62', $leave->rejection_notes ?? '');
        }

        // --- 8. Signatories ---
        $officialLabel = '';
        if (isset($dept)) {
            // Department head name for recommendation
            if (!empty($dept->EmpNo) && $dept->EmpNo !== 'UNASSIGNED') {
                $headUser = User::where('EmpNo', $dept->EmpNo)->first();
                if ($headUser) {
                    $headParts = array_filter([
                        $headUser->first_name ?? '',
                        $headUser->middle_name ?? '',
                        $headUser->last_name ?? '',
                    ]);
                    $deptHeadName = implode(' ', $headParts);
                    if (empty($deptHeadName)) {
                        $deptHeadName = $headUser->name ?? '';
                    }
                    $sheet->setCellValue('H60', $deptHeadName);
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

        $filename = "Leave_Form_{$leave->id}_" . now()->format('Ymd_His') . '.xlsx';

        // Audit log
        $user = auth()->user();
        Log::info('Leave form printed (Excel)', [
            'leave_request_id' => $leave->id,
            'printed_by'       => $user->id ?? null,
            'role'             => $user->access_level ?? null,
            'timestamp'        => now()->toDateTimeString(),
            'filename'         => $filename,
            'lock_applied'     => $lockApplied,
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
        if (!is_numeric($val)) {
            return (string)$val;
        }
        $float = (float)$val;
        if ($float == (int)$float) {
            return (string)(int)$float;
        }
        return rtrim(rtrim(number_format($float, 3, '.', ''), '0'), '.');
    }

    /**
     * Walk the department parent chain to find the root (top-level) department.
     * Returns the root Department or null. Limits depth to prevent infinite loops.
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
                break; // circular reference guard
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
     * Determine whether a department falls under the Vice Mayor's office
     * by walking its parent chain to the root and checking the name.
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
     * Returns [name, designation, label] where label is 'City Mayor' or 'City Vice Mayor'.
     */
    private function resolveExecutiveSignatory(?Department $dept, ?Setting $settings): array
    {
        if (!$settings) {
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
    private function protectAllSheets(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ?User $employee): void
    {
        $first = $employee->first_name ?? ($employee->firstname ?? '');
        $last  = $employee->last_name ?? ($employee->lastname ?? '');
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
