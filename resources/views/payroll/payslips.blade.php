@extends('dashboards.layout', [
    'title' => 'Payslips',
    'subtitle' => 'Generated payslips for approved payroll runs.',
])

@section('top_actions')
    <div class="header-actions">
        <form method="GET" action="{{ route('payroll.payslips.index') }}" class="filter-form" style="display:inline-flex;gap:8px;">
            <select name="payroll_run_id" class="form-input">
                <option value="">All Runs</option>
                @foreach($runs as $run)
                    <option value="{{ $run->id }}" @selected(request('payroll_run_id') == $run->id)>Run #{{ $run->id }} — {{ $run->period }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm">Filter</button>
        </form>
        <button type="button" class="btn btn-sm" onclick="document.getElementById('generatePayslipModal').showModal()"><i class="fas fa-file-invoice-dollar"></i> Generate Payslips</button>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$payslips">
        <table class="hris-table" id="payslips-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Employee</th>
                    <th>Payroll Run</th>
                    <th>PDF</th>
                    <th>Generated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payslips as $ps)
                    <tr id="payslip-row-{{ $ps->id }}"
                        data-employee="{{ $ps->employee->name ?? '—' }}"
                        data-run="Run #{{ $ps->payroll_run_id }} — {{ $ps->payrollRun->period ?? '' }}"
                        data-pdf="{{ $ps->pdf_path ? asset($ps->pdf_path) : '' }}"
                        data-date="{{ $ps->created_at->format('M d, Y H:i') }}">
                        <td>{{ $ps->id }}</td>
                        <td>{{ $ps->employee->name ?? '—' }}</td>
                        <td>Run #{{ $ps->payroll_run_id }} — {{ $ps->payrollRun->period ?? '' }}</td>
                        <td>
                            @if($ps->pdf_path)
                                <a href="{{ asset($ps->pdf_path) }}" target="_blank" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-download"></i></a>
                            @else
                                <span class="text-muted">Pending</span>
                            @endif
                        </td>
                        <td>{{ $ps->created_at->format('M d, Y') }}</td>
                        <td>
                            <div class="action-btns">
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openShowPayslip({{ $ps->id }})">View</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No payslips generated yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
<dialog id="generatePayslipModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Generate Payslips</h3>
            <span class="record-email">Generate payslips from an approved payroll run</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.payslips.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-run">Payroll Run (Approved)</label>
            <select name="payroll_run_id" id="c-run" class="form-input" required>
                <option value="">Select run</option>
                @foreach($approvedRuns as $run)
                    <option value="{{ $run->id }}">Run #{{ $run->id }} — {{ $run->period }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Generate Payslips</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="showPayslipModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div><h3 style="margin:0">Payslip Details</h3></div>
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
    var pdfLink = row.dataset.pdf ? '<a href="' + row.dataset.pdf + '" target="_blank">Download PDF</a>' : 'Not yet generated';
    document.getElementById('showPayslipBody').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.employee + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Payroll Run</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.run + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>PDF</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + pdfLink + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Generated</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.date + '</td></tr>' +
        '</tbody></table>';
    document.getElementById('showPayslipModal').showModal();
}
@if ($errors->any()) document.getElementById('generatePayslipModal').showModal(); @endif
</script>
@endsection
