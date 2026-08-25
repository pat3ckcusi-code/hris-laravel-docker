@extends('dashboards.layout', ['title' => 'Leave Print', 'subtitle' => 'Print preview'])

@section('content')
<div style="max-width:760px;margin:36px auto;font-family:sans-serif">
    <h2>Leave Print (Fallback)</h2>
    <p>The PDF template is not available. Below are the leave details you requested to print.</p>

    @php $lv = $leaves->first(); @endphp

    @php
        // Prefer live leave_balances values from the employee record; fallback to snapshots on the leave request
        $empLB = $lv->user ? $lv->user->leaveBalance : null;
        $vlBal = floatval($empLB->VL ?? $lv->balance_vacation_leave ?? 0);
        $slBal = floatval($empLB->SL ?? $lv->balance_sick_leave ?? 0);
        $wlnsBal = floatval($empLB->WLNS ?? $lv->balance_wellness_leave ?? 0);
        $splBal = floatval($empLB->SPL ?? $lv->balance_special_leave_privilege ?? 0);
        $ctoBal = floatval($empLB->CTO ?? $lv->balance_cto ?? 0);
        $spBal = floatval($empLB->SP ?? $lv->balance_solo_parent_leave ?? 0);

        // Use the printing preview JSON (saved at filing or on allow-print). Keep only VL/SL keys.
        $preview = [];
        if (!empty($lv->printing_deduction_details)) {
            try { $preview = json_decode($lv->printing_deduction_details, true) ?: []; } catch (\Exception $e) { $preview = []; }
        }

        // Total Earned is the sum of all deductible balances from leave_balances
        $totalEarned = $vlBal + $slBal + $wlnsBal + $splBal + $ctoBal + $spBal;

        // Less This Application is sum of VL+SL values from the preview (ignore other types)
        $lessThis = 0.0;
        if (!empty($preview)) {
            if (isset($preview['VL'])) $lessThis += floatval($preview['VL']);
            if (isset($preview['SL'])) $lessThis += floatval($preview['SL']);
        } else {
            // fallback: infer from leave type if no preview exists
            $lt = strtolower($lv->leave_type ?? '');
            if (strpos($lt, 'vacation') !== false || strpos($lt, 'vl') !== false) $lessThis = floatval($lv->paid_days ?? 0);
            elseif (strpos($lt, 'sick') !== false || strpos($lt, 'sl') !== false) $lessThis = floatval($lv->paid_days ?? 0);
        }

        $balance = $totalEarned - $lessThis;
    @endphp

    <table style="width:100%;border-collapse:collapse">
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #ddd">{{ optional($lv->user)->name ?? '-' }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #ddd">
            @if($lv->hasMixedLeaveTypes())
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem">
                    <thead><tr><th style="text-align:left">Date</th><th style="text-align:left">Type</th><th style="text-align:right">Days</th></tr></thead>
                    <tbody>
                    @foreach($lv->leaveDatesBreakdown() as $d)
                        <tr><td>{{ $d['label'] }}</td><td>{{ $d['leave_type'] }}</td><td style="text-align:right">{{ $d['days'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                {{ $lv->leave_type }}
            @endif
        </td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Period</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->formattedPeriod() }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Total Days</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->total_days ?? '-' }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Approved At</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->updated_at ? $lv->updated_at->format('M d, Y') : '-' }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Reason</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->reason ?? '-' }}</td></tr>
    </table>

    <h3 style="margin-top:24px">Section 7.A - Certification of Leave Credits</h3>
    <table style="width:100%;border-collapse:collapse">
        @php
            $reasonText = $lv->reason ?? '';
            // use printing preview or leave type to determine if this is Wellness
            $isWellness = false;
            if (!empty($lv->printing_deduction_details)) {
                try { $pr = json_decode($lv->printing_deduction_details, true) ?: []; } catch (\Exception $e) { $pr = []; }
                if (!empty($pr) && isset($pr['WLNS']) && floatval($pr['WLNS']) > 0) $isWellness = true;
            }
            if (!$isWellness) {
                $lt = strtolower((string)($lv->leave_type ?? ''));
                if (strpos($lt, 'wellness') !== false || strpos($lt, 'wlns') !== false) $isWellness = true;
            }
            if ($isWellness) { $reasonText = 'Wellness'; }
        @endphp
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Total Earned</strong></td><td style="padding:8px;border:1px solid #ddd">{{ number_format($totalEarned, 3, '.', '') }} days</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Less This Application</strong></td><td style="padding:8px;border:1px solid #ddd">{{ number_format($lessThis, 3, '.', '') }} days</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Balance</strong></td><td style="padding:8px;border:1px solid #ddd">{{ number_format($balance, 3, '.', '') }} days</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Reason</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $reasonText ?: '-' }}</td></tr>
    </table>

    <div style="margin-top:16px;text-align:right">
        <button onclick="window.print()" style="padding:8px 12px;border-radius:6px">Print</button>
        <a href="{{ url()->previous() }}" style="margin-left:8px">Back</a>
    </div>
</div>
@endsection
