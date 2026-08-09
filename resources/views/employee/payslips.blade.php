@extends('dashboards.layout', [
    'title' => 'My Payslips',
    'subtitle' => 'Track your pay and download your payslip history.',
])

@section('content')

@if($latestPayslip)
    <div class="hero-panel payslip-spotlight">
        <div class="hero-copy">
            <p class="eyebrow">Latest Payslip &middot; {{ $latestPayslip->payrollRun->period ?? '-' }}</p>
            <p class="payslip-spotlight-amount">₱{{ number_format($latestPayslip->net_pay, 2) }}</p>
            <p>Net take-home pay for your most recent payroll run.</p>
        </div>

        <div class="payslip-spotlight-grid">
            <div class="payslip-spotlight-item">
                <span class="metric-label">Basic Salary</span>
                <strong>₱{{ number_format($latestPayslip->basic_salary, 2) }}</strong>
            </div>
            <div class="payslip-spotlight-item">
                <span class="metric-label">Earnings</span>
                <strong>₱{{ number_format($latestPayslip->gross_pay, 2) }}</strong>
            </div>
            <div class="payslip-spotlight-item">
                <span class="metric-label">Deductions</span>
                <strong>₱{{ number_format($latestPayslip->total_deductions, 2) }}</strong>
            </div>
        </div>

        <div class="hero-actions">
            <span class="status-chip status-{{ $latestPayslip->payrollRun->status ?? 'draft' }}">{{ ucfirst($latestPayslip->payrollRun->status ?? '-') }}</span>
            <a href="{{ route('dashboard.employee.payslips.download-excel', $latestPayslip->id) }}" class="action-btn primary-action"><i class="fas fa-file-excel"></i> Download Excel</a>
        </div>
    </div>

    <div class="plantilla-stats">
        <div class="stat-tile stat-info">
            <div class="stat-icon"><i class="fas fa-receipt"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['total_payslips']) }}</div>
                <div class="stat-label">Total Payslips</div>
            </div>
        </div>
        <div class="stat-tile stat-promo">
            <div class="stat-icon"><i class="fas fa-sack-dollar"></i></div>
            <div>
                <div class="stat-value">₱{{ number_format($stats['ytd_net_pay'], 2) }}</div>
                <div class="stat-label">{{ $currentYear }} Net Pay</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div>
                <div class="stat-value">₱{{ number_format($stats['average_net_pay'], 2) }}</div>
                <div class="stat-label">Average Net Pay</div>
            </div>
        </div>
    </div>
@endif

<x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$payslips">
    <table class="hris-table">
        <thead>
            <tr>
                <th>Period</th>
                <th class="text-right">Basic Salary</th>
                <th class="text-right">Earnings</th>
                <th class="text-right">Deductions</th>
                <th class="text-right">Net Pay</th>
                <th class="text-center">Status</th>
                <th class="text-center">Download</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payslips as $payslip)
            <tr class="{{ $latestPayslip && $payslip->id === $latestPayslip->id ? 'payslip-row-latest' : '' }}">
                <td>{{ $payslip->payrollRun->period ?? '-' }}</td>
                <td class="text-right">₱{{ number_format($payslip->basic_salary ?? 0, 2) }}</td>
                <td class="text-right">₱{{ number_format($payslip->gross_pay ?? 0, 2) }}</td>
                <td class="text-right">₱{{ number_format($payslip->total_deductions ?? 0, 2) }}</td>
                <td class="text-right font-weight-bold">₱{{ number_format($payslip->net_pay ?? 0, 2) }}</td>
                <td class="text-center">
                    <span class="status-chip status-{{ $payslip->payrollRun->status ?? 'draft' }}">{{ ucfirst($payslip->payrollRun->status ?? '-') }}</span>
                </td>
                <td class="text-center">
                    <a href="{{ route('dashboard.employee.payslips.download-excel', $payslip->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-file-excel"></i> Excel</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7"><p class="empty-state">No payslips found yet. They'll appear here once Payroll generates them.</p></td>
            </tr>
            @endforelse
        </tbody>
    </table>
</x-hris.table-layout>
@endsection
