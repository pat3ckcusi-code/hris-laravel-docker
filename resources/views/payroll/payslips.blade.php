@extends('dashboards.layout', [
    'title' => 'Payslips',
    'subtitle' => 'Generated payslips for locked payroll runs.',
])

@section('top_actions')
    <div class="header-actions">
        <button type="button" class="btn btn-sm" onclick="document.getElementById('generatePayslipModal').showModal()"><i class="fas fa-file-invoice-dollar"></i> Generate Payslips</button>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <div class="plantilla-stats">
        <div class="stat-tile stat-info">
            <div class="stat-icon"><i class="fas fa-receipt"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['total_payslips']) }}</div>
                <div class="stat-label">Total Payslips</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['employees_covered']) }}</div>
                <div class="stat-label">Employees Covered</div>
            </div>
        </div>
        <div class="stat-tile stat-promo">
            <div class="stat-icon"><i class="fas fa-sack-dollar"></i></div>
            <div>
                <div class="stat-value">₱{{ number_format($stats['total_net_pay'], 2) }}</div>
                <div class="stat-label">Total Net Pay</div>
            </div>
        </div>
    </div>

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$payslips">
        <x-slot:filters>
            <form method="GET" action="{{ route('payroll.payslips.index') }}" class="hris-search-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search employee name or EmpNo…" class="hris-search-input" style="max-width:260px">
                <select name="payroll_run_id" class="form-input" style="max-width:220px">
                    <option value="">All Runs</option>
                    @foreach($runs as $run)
                        <option value="{{ $run->id }}" @selected(request('payroll_run_id') == $run->id)>Run #{{ $run->id }} - {{ $run->period }}</option>
                    @endforeach
                </select>
                <select name="department_id" class="form-input" style="max-width:200px">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->Dept_id }}" @selected($departmentId == $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm">Filter</button>
                @if($search !== '' || $departmentId !== '' || request()->filled('payroll_run_id'))
                    <a href="{{ route('payroll.payslips.index') }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        <table class="hris-table" id="payslips-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Payroll Run</th>
                    <th class="text-right">Net Pay</th>
                    <th>Generated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payslips as $ps)
                    <tr id="payslip-row-{{ $ps->id }}"
                        data-employee="{{ $ps->employee->name ?? '-' }}"
                        data-department="{{ $ps->employee->department->Dept_name ?? '-' }}"
                        data-run="Run #{{ $ps->payroll_run_id }} - {{ $ps->payrollRun->period ?? '' }}"
                        data-run-status="{{ $ps->payrollRun->status ?? '' }}"
                        data-download-excel="{{ route('payroll.payslips.download-excel', $ps->id) }}"
                        data-date="{{ $ps->created_at->format('M d, Y H:i') }}"
                        data-basic-salary="{{ number_format($ps->basic_salary, 2) }}"
                        data-gross-pay="{{ number_format($ps->gross_pay, 2) }}"
                        data-mandatory-deductions="{{ number_format($ps->mandatory_deductions, 2) }}"
                        data-loan-deduction="{{ number_format($ps->loan_deduction, 2) }}"
                        data-other-deductions="{{ number_format($ps->other_deductions, 2) }}"
                        data-lwop-deduction="{{ number_format($ps->lwop_deduction, 2) }}"
                        data-total-deductions="{{ number_format($ps->total_deductions, 2) }}"
                        data-net-pay="{{ number_format($ps->net_pay, 2) }}">
                        <td>{{ $ps->id }}</td>
                        <td>{{ $ps->employee->name ?? '-' }}</td>
                        <td>{{ $ps->employee->department->Dept_name ?? '-' }}</td>
                        <td>
                            Run #{{ $ps->payroll_run_id }} - {{ $ps->payrollRun->period ?? '' }}
                            @if($ps->payrollRun)
                                <br><span class="status-chip status-{{ $ps->payrollRun->status }}">{{ ucfirst($ps->payrollRun->status) }}</span>
                            @endif
                        </td>
                        <td class="text-right">₱{{ number_format($ps->net_pay, 2) }}</td>
                        <td>{{ $ps->created_at->format('M d, Y') }}</td>
                        <td>
                            <div class="action-btns">
                                <a href="{{ route('payroll.payslips.download-excel', $ps->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-file-excel"></i> Excel</a>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openShowPayslip({{ $ps->id }})">View</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><p class="empty-state">No payslips match the current filters.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
<dialog id="generatePayslipModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-file-invoice-dollar"></i></span>
            <div>
                <h3>Generate Payslips</h3>
                <p class="modal-subtitle">Generate payslips from a locked payroll run</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.payslips.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-run"><i class="fas fa-lock"></i> Payroll Run (Locked)</label>
            <select name="payroll_run_id" id="c-run" class="form-input" required>
                <option value="">Select run</option>
                @foreach($lockedRuns as $run)
                    <option value="{{ $run->id }}">Run #{{ $run->id }} - {{ $run->period }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-file-invoice-dollar"></i> Generate Payslips</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="showPayslipModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-receipt"></i></span>
            <div><h3>Payslip Details</h3></div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <div id="showPayslipBody" style="margin-top:12px"></div>
    <form method="dialog" class="form-actions" style="margin-top:12px;text-align:right">
        <button class="btn btn-outline" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openShowPayslip(id) {
    var row = document.getElementById('payslip-row-' + id);
    if (!row) return;
    var d = row.dataset;
    var excelLink = '<a href="' + d.downloadExcel + '" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-file-excel"></i> Download Excel</a>';
    document.getElementById('showPayslipBody').innerHTML =
        '<div class="detail-card">' +
            '<div class="detail-row"><strong>Employee</strong>' + d.employee + '</div>' +
            '<div class="detail-row"><strong>Department</strong>' + d.department + '</div>' +
            '<div class="detail-row"><strong>Payroll Run</strong>' + d.run + (d.runStatus ? ' <span class="status-chip status-' + d.runStatus + '">' + (d.runStatus.charAt(0).toUpperCase() + d.runStatus.slice(1)) + '</span>' : '') + '</div>' +
            '<div class="detail-row"><strong>Generated</strong>' + d.date + '</div>' +
            '<div class="detail-row"><strong>Basic Salary</strong>₱' + d.basicSalary + '</div>' +
            '<div class="detail-row"><strong>Gross Pay</strong>₱' + d.grossPay + '</div>' +
            '<div class="detail-row"><strong>Mandatory Deductions</strong>₱' + d.mandatoryDeductions + '</div>' +
            '<div class="detail-row"><strong>Loan Deduction</strong>₱' + d.loanDeduction + '</div>' +
            '<div class="detail-row"><strong>Other Deductions</strong>₱' + d.otherDeductions + '</div>' +
            '<div class="detail-row"><strong>LWOP Deduction</strong>₱' + d.lwopDeduction + '</div>' +
            '<div class="detail-row"><strong>Total Deductions</strong>₱' + d.totalDeductions + '</div>' +
            '<div class="detail-row"><strong>Net Pay</strong>₱' + d.netPay + '</div>' +
            '<div class="detail-row"><strong>Excel</strong>' + excelLink + '</div>' +
        '</div>';
    document.getElementById('showPayslipModal').showModal();
}
@if ($errors->any()) document.getElementById('generatePayslipModal').showModal(); @endif
</script>
@endsection
