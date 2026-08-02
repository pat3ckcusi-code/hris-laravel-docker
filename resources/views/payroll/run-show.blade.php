@extends('dashboards.layout', [
    'title' => 'Payroll Run #' . $run->id,
    'subtitle' => 'Period: ' . $run->period . ($run->period_start ? ' (' . $run->period_start->format('M d') . ' – ' . $run->period_end->format('M d, Y') . ')' : ''),
])

@section('top_actions')
    <div class="header-actions">
        @if(!$run->locked_at)
            <form method="POST" action="{{ route('payroll.runs.compute', $run->id) }}" style="display:inline" id="compute-form">
                @csrf
                <button type="button" class="btn btn-sm" id="compute-btn" onclick="confirmCompute()"><i class="fas fa-calculator"></i> Compute</button>
            </form>
            <form method="POST" action="{{ route('payroll.runs.lock', $run->id) }}" style="display:inline" id="lock-form">
                @csrf
                <button type="button" class="btn btn-sm btn-danger" id="lock-btn" onclick="confirmLock()"><i class="fas fa-lock"></i> Lock</button>
            </form>
        @endif
        <a href="{{ route('payroll.runs.export', $run->id) }}{{ $selectedDepartment !== '' ? '?department='.$selectedDepartment : '' }}" class="btn btn-sm btn-outline"><i class="fas fa-download"></i> Export</a>
        <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('customExportModal').showModal()"><i class="fas fa-list-check"></i> Custom Export</button>
        <a href="{{ route('payroll.runs.index') }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection

@section('tiles')
    <article class="tile metric-tile">
        <span class="metric-label">Status</span>
        <strong><span class="status-chip status-{{ $run->status }}">{{ ucfirst($run->status) }}</span></strong>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label">Employees</span>
        <strong>{{ $run->details->count() }}</strong>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label">Total Net Pay</span>
        <strong>₱{{ number_format($run->details->sum('net_pay'), 2) }}</strong>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label">Exceptions</span>
        <strong>{{ $run->exceptions->where('resolved_flag', false)->count() }}</strong>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label">Employee Types</span>
        <strong>{{ $run->eligible_employee_types ? implode(', ', $run->eligible_employee_types) : 'All' }}</strong>
    </article>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('computed_summary'))
        <div class="notice success" style="display:flex;align-items:center;gap:12px">
            <i class="fas fa-circle-check" style="font-size:1.4rem"></i>
            <div>
                <strong>Payroll Computed Successfully</strong><br>
                <span style="font-weight:400">{{ session('computed_summary')['count'] }} employee(s) processed for {{ session('computed_summary')['period'] }}.</span>
            </div>
        </div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <section class="payroll-section">
        <h2>Payroll Details</h2>
        @if($run->details->count())
            <form method="GET" action="{{ route('payroll.runs.show', $run->id) }}" class="plantilla-filter-form" style="margin-bottom:14px">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search employee name or Employee Agency Number..." class="hris-search-input" style="min-width:260px">
                <select name="department" class="hris-filter-select">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->Dept_id }}" @selected($selectedDepartment === (string) $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-filter"></i> Filter</button>
                @if(request()->hasAny(['search', 'department']))
                    <a href="{{ route('payroll.runs.show', $run->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
                @endif
            </form>

            @if($details->total())
                <div class="hris-table-wrapper">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Basic Salary</th>
                            <th>Matrix Tranche</th>
                            <th>Allowances</th>
                            <th>Gross Pay</th>
                            <th>GSIS</th>
                            <th>PhilHealth</th>
                            <th>Pag-IBIG</th>
                            <th>BIR</th>
                            <th>Loans</th>
                            @foreach($otherDeductionTypes as $odt)
                                <th>{{ $odt->type }}</th>
                            @endforeach
                            <th>LWOP</th>
                            <th>Net Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($details as $detail)
                            <tr>
                                <td>{{ $detail->employee->name ?? '-' }}</td>
                                <td>₱{{ number_format($detail->basic_salary, 2) }}</td>
                                <td>
                                    @forelse($detail->basic_salary_breakdown ?? [] as $segment)
                                        <div style="{{ !$loop->last ? 'margin-bottom:6px' : '' }}">
                                            @if(!($segment['is_base'] ?? true))
                                                <span class="text-muted">Adjustment —</span>
                                            @endif
                                            <strong>{{ $segment['date_range'] }}</strong>
                                            ({{ $segment['days'] }} {{ Str::plural('day', $segment['days']) }}):
                                            {{ $segment['effective_date'] }}
                                            @if($segment['ordinance_reference'])
                                                <span class="text-muted">({{ $segment['ordinance_reference'] }})</span>
                                            @endif
                                            — {{ $segment['amount'] < 0 ? '-' : '' }}₱{{ number_format(abs($segment['amount']), 2) }}
                                            @if($segment['not_yet_effective'] ?? false)
                                                <br><span class="status-chip status-draft" title="This tranche was not yet effective by the end of this run's period — the earliest available rate was used as a fallback.">⚠ not yet effective</span>
                                            @endif
                                        </div>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>
                                <td>₱{{ number_format($detail->earnings, 2) }}</td>
                                <td>₱{{ number_format($detail->gross_pay ?? ($detail->basic_salary + $detail->earnings), 2) }}</td>
                                <td>₱{{ number_format($detail->gsis_deduction ?? 0, 2) }}</td>
                                <td>₱{{ number_format($detail->philhealth_deduction ?? 0, 2) }}</td>
                                <td>₱{{ number_format($detail->pagibig_deduction ?? 0, 2) }}</td>
                                <td>₱{{ number_format($detail->bir_deduction ?? 0, 2) }}</td>
                                <td>₱{{ number_format($detail->loan_deduction ?? 0, 2) }}</td>
                                @foreach($otherDeductionTypes as $odt)
                                    @php
                                        $otherLine = collect($detail->deduction_breakdown ?? [])
                                            ->first(fn ($item) => ($item['category'] ?? null) === 'other' && ($item['label'] ?? null) === $odt->type);
                                    @endphp
                                    <td>₱{{ number_format($otherLine['amount'] ?? 0, 2) }}</td>
                                @endforeach
                                <td>₱{{ number_format($detail->lwop_deduction ?? 0, 2) }}</td>
                                <td><strong>₱{{ number_format($detail->net_pay, 2) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>

                <x-hris.table-pagination :paginator="$details" />
            @else
                <p class="empty-state">No employees match your search/filter.</p>
            @endif
        @else
            <p class="empty-state">No payroll details computed yet. Click <strong>Compute</strong> to generate.</p>
        @endif
    </section>
@endsection

@section('modals')
<dialog id="customExportModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-list-check"></i></span>
            <div>
                <h3>Custom Export</h3>
                <p class="modal-subtitle">Pick departments to include, and optionally change how each one prints on the form</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.runs.export.custom', $run->id) }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="custom-export-search"><i class="fas fa-search"></i> Search departments</label>
            <input type="text" id="custom-export-search" class="form-input" placeholder="Type to filter&hellip;" oninput="filterCustomExportDepartments(this.value)">
        </div>

        <div style="max-height:340px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-top:8px">
            @foreach($departments as $dept)
                <div class="custom-export-row" data-search="{{ strtolower($dept->Dept_name) }}" style="padding:6px 0;border-bottom:1px solid #f1f5f9">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="departments[]" value="{{ $dept->Dept_id }}" onchange="toggleCustomExportName(this)">
                        <span>{{ $dept->Dept_name }}</span>
                    </label>
                    <div class="custom-export-name-field" style="display:none;margin-top:6px;padding-left:24px">
                        <input type="text" name="department_names[{{ $dept->Dept_id }}]" class="form-input" value="{{ $dept->Dept_name }}" placeholder="Name to print on the form">
                    </div>
                </div>
            @endforeach
        </div>

        <div class="form-actions" style="margin-top:14px">
            <button type="submit" class="btn"><i class="fas fa-download"></i> Export Selected</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function toggleCustomExportName(checkbox) {
    const nameField = checkbox.closest('.custom-export-row').querySelector('.custom-export-name-field');
    nameField.style.display = checkbox.checked ? 'block' : 'none';
}

function filterCustomExportDepartments(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('.custom-export-row').forEach(function (row) {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}

function confirmCompute() {
    const message = 'This (re)calculates pay for every active employee this period from current DTR, leave, and salary data. Any previously computed figures for this run will be overwritten.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Compute payroll for this run?',
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Compute',
        }).then((result) => { if (result.isConfirmed) startComputing(); });
    } else if (confirm(message)) {
        startComputing();
    }
}

function startComputing() {
    const computeBtn = document.getElementById('compute-btn');
    const lockBtn = document.getElementById('lock-btn');
    computeBtn.disabled = true;
    computeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Computing...';
    if (lockBtn) lockBtn.disabled = true;
    document.getElementById('compute-form').submit();
}

function confirmLock() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Lock this payroll run?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Lock',
        }).then((result) => { if (result.isConfirmed) document.getElementById('lock-form').submit(); });
    } else if (confirm('Lock this payroll run?')) {
        document.getElementById('lock-form').submit();
    }
}
</script>
@endsection
