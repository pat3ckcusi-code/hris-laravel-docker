@extends('dashboards.layout', [
    'title' => 'My Payslips',
    'subtitle' => 'View your payslip history.',
])

@section('content')
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
            </tr>
        </thead>
        <tbody>
            @forelse ($payslips as $payslip)
            <tr>
                <td>{{ $payslip->payrollRun->period ?? '—' }}</td>
                <td class="text-right">₱{{ number_format($payslip->basic_salary ?? 0, 2) }}</td>
                <td class="text-right">₱{{ number_format($payslip->total_earnings ?? 0, 2) }}</td>
                <td class="text-right">₱{{ number_format($payslip->total_deductions ?? 0, 2) }}</td>
                <td class="text-right font-weight-bold">₱{{ number_format($payslip->net_pay ?? 0, 2) }}</td>
                <td class="text-center">
                    <x-hris.status-badge :status="$payslip->payrollRun->status ?? 'pending'" />
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-center text-muted">No payslips found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</x-hris.table-layout>
@endsection
