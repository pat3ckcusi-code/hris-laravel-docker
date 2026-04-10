@extends('dashboards.layout', [
    'title' => 'My Payslips',
    'subtitle' => 'View your payslip history.',
])

@section('content')
<div style="overflow-x:auto;">
    <table class="payroll-table" style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th style="padding:10px 12px; text-align:left; border-bottom:2px solid #e2e8f0;">Period</th>
                <th style="padding:10px 12px; text-align:right; border-bottom:2px solid #e2e8f0;">Basic Salary</th>
                <th style="padding:10px 12px; text-align:right; border-bottom:2px solid #e2e8f0;">Earnings</th>
                <th style="padding:10px 12px; text-align:right; border-bottom:2px solid #e2e8f0;">Deductions</th>
                <th style="padding:10px 12px; text-align:right; border-bottom:2px solid #e2e8f0;">Net Pay</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payslips as $payslip)
            <tr>
                <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9;">{{ $payslip->payrollRun->period ?? '—' }}</td>
                <td style="padding:10px 12px; text-align:right; border-bottom:1px solid #f1f5f9;">₱{{ number_format($payslip->basic_salary ?? 0, 2) }}</td>
                <td style="padding:10px 12px; text-align:right; border-bottom:1px solid #f1f5f9;">₱{{ number_format($payslip->total_earnings ?? 0, 2) }}</td>
                <td style="padding:10px 12px; text-align:right; border-bottom:1px solid #f1f5f9;">₱{{ number_format($payslip->total_deductions ?? 0, 2) }}</td>
                <td style="padding:10px 12px; text-align:right; border-bottom:1px solid #f1f5f9; font-weight:600;">₱{{ number_format($payslip->net_pay ?? 0, 2) }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">
                    <span class="badge-{{ $payslip->payrollRun->status ?? 'pending' }}">{{ ucfirst($payslip->payrollRun->status ?? 'pending') }}</span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" style="padding:20px; text-align:center; color:#94a3b8;">No payslips found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">
    {{ $payslips->links() }}
</div>
@endsection
