<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #0f172a; }
        h2 { text-align: center; margin: 0 0 2px; font-size: 15px; }
        p.subtitle { text-align: center; color: #475569; margin: 0 0 14px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 3px 4px; font-size: 11px; }
        .info-table td.label { font-weight: bold; width: 90px; }
        .money-table { margin-top: 10px; border: 1px solid #999; }
        .money-table th, .money-table td { border: 1px solid #999; padding: 4px 6px; }
        .money-table th { background: #bdd7ee; text-align: left; }
        .amount { text-align: right; }
        .section-title { background: #e2e8f0; font-weight: bold; }
        .totals-row td { font-weight: bold; }
        .net-pay-row td { font-weight: bold; font-size: 13px; background: #dcfce7; }
        .signatories { width: 100%; margin-top: 30px; }
        .signatories td { width: 33.33%; text-align: center; vertical-align: top; padding: 0 8px; font-size: 10px; }
        .sig-line { border-top: 1px solid #0f172a; margin-top: 30px; padding-top: 3px; }
        .sig-role { color: #475569; }
        p.disclaimer { text-align: center; margin-top: 20px; font-size: 9px; color: #64748b; font-style: italic; }
    </style>
</head>
<body>
    <h2>{{ $orgName }}</h2>
    <p class="subtitle">PAYSLIP for the month of {{ strtoupper($run->period) }}</p>

    <table class="info-table">
        <tr>
            <td class="label">Name:</td>
            <td>{{ $employee->name }}</td>
            <td class="label">ID No.:</td>
            <td>{{ $employee->EmpNo ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Position:</td>
            <td>{{ $position ?? '-' }}</td>
            <td class="label">ATM No.:</td>
            <td>{{ $employee->atm_no ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Department:</td>
            <td colspan="3">{{ $department ?? '-' }}</td>
        </tr>
    </table>

    <table class="money-table">
        <tr class="section-title"><th colspan="2">EARNINGS</th></tr>
        <tr>
            <td>Monthly Rate</td>
            <td class="amount">{{ number_format($payslip->basic_salary, 2) }}</td>
        </tr>
        @if($payslip->gross_pay > $payslip->basic_salary)
            <tr>
                <td>Allowances</td>
                <td class="amount">{{ number_format($payslip->gross_pay - $payslip->basic_salary, 2) }}</td>
            </tr>
        @endif
    </table>

    <table class="money-table">
        <tr class="section-title"><th colspan="2">DEDUCTIONS</th></tr>
        @forelse ($payslip->deduction_breakdown ?? [] as $line)
            <tr>
                <td>{{ $line['label'] }}{{ !empty($line['provider']) ? ' ('.$line['provider'].')' : '' }}</td>
                <td class="amount">{{ number_format($line['amount'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="2">No deductions.</td></tr>
        @endforelse
        <tr class="totals-row">
            <td>TOTAL DEDUCTIONS</td>
            <td class="amount">{{ number_format($payslip->total_deductions, 2) }}</td>
        </tr>
    </table>

    <table class="money-table">
        <tr class="net-pay-row">
            <td>NET TAKE HOME PAY</td>
            <td class="amount">{{ number_format($payslip->net_pay, 2) }}</td>
        </tr>
    </table>

    <table class="signatories">
        <tr>
            <td>
                <div class="sig-line">{{ $preparedByName ?? '-' }}</div>
                <div class="sig-role">Prepared by{{ $preparedByDesignation ? ' — '.$preparedByDesignation : '' }}</div>
            </td>
            <td>
                <div class="sig-line">{{ $certifiedByName ?? '-' }}</div>
                <div class="sig-role">Certified Correct — {{ $certifiedByDesignation }}</div>
            </td>
            <td>
                <div class="sig-line">{{ $employee->name }}</div>
                <div class="sig-role">Acknowledgement — Signature Over Printed Name</div>
            </td>
        </tr>
    </table>

    <p class="disclaimer">Note: This cannot be used for contracting Loans.</p>
</body>
</html>
