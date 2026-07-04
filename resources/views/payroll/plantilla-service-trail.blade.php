@extends('dashboards.layout', [
    'title' => 'Employee Service Trail',
    'subtitle' => 'Positions held, promotions, and assignment history per employee.',
])

@section('top_actions')
    <a href="{{ route('payroll.plantilla.reports') }}" class="btn btn-sm btn-outline plantilla-nav-btn"><i class="fas fa-chart-bar"></i> Reports</a>
    <a href="{{ route('payroll.plantilla.index') }}" class="btn btn-sm btn-outline">Back to Plantilla</a>
@endsection

@section('content')
    <form method="GET" action="{{ route('payroll.plantilla.service-trail') }}" class="plantilla-filter-form" style="margin-bottom:20px">
        <select name="employee_id" class="hris-filter-select" style="min-width:320px;max-width:100%">
            <option value="">Select employee...</option>
            @foreach($employees as $emp)
                <option value="{{ $emp->id }}" @selected(request('employee_id') == $emp->id)>
                    {{ $emp->last_name ? "{$emp->last_name}, {$emp->first_name}" : $emp->name }}{{ $emp->EmpNo ? " ({$emp->EmpNo})" : '' }}{{ $emp->designation ? " -{$emp->designation}" : '' }}
                </option>
            @endforeach
        </select>
        <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm">View Trail</button>
    </form>

    @if($employee)
        @php
            $current = $assignments->firstWhere('end_date', null);
            $initials = mb_strtoupper(mb_substr($employee->first_name ?: $employee->name, 0, 1).mb_substr($employee->last_name ?: '', 0, 1));
        @endphp

        {{-- Employee header --}}
        <div class="profile-card">
            <div class="profile-avatar">{{ $initials ?: '?' }}</div>
            <div class="profile-body">
                <div class="profile-name">{{ $employee->last_name ? "{$employee->last_name}, {$employee->first_name}" : $employee->name }}{{ $employee->EmpNo ? " ({$employee->EmpNo})" : '' }}</div>
                <div class="profile-position">
                    @if($current && $current->plantilla)
                        {{ $current->plantilla->title }}
                        <span class="sg-badge">SG {{ $current->plantilla->salary_grade }} · Step {{ $current->plantilla->step }}</span>
                        @if($current->plantilla->item_number)<span class="item-badge">Item {{ $current->plantilla->item_number }}</span>@endif
                    @else
                        No active plantilla assignment
                    @endif
                </div>
                <div class="profile-meta">
                    <span class="meta-chip"><i class="fas fa-building"></i>{{ $employee->department->Dept_name ?? 'No department' }}</span>
                    <span class="meta-chip"><i class="fas fa-id-badge"></i>{{ $employee->employee_type ?? 'Unspecified type' }}</span>
                    <span class="meta-chip"><i class="fas fa-calendar-check"></i>Original appointment: {{ $employee->date_of_original_appointment?->format('M d, Y') ?? '-' }}</span>
                    <span class="meta-chip"><i class="fas fa-arrow-trend-up"></i>Last promotion: {{ $employee->date_of_last_promotion?->format('M d, Y') ?? '-' }}</span>
                </div>
            </div>
        </div>

        {{-- Position history --}}
        <section class="payroll-section">
            <div class="section-header">
                <h2><i class="fas fa-route"></i>Position History</h2>
                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="document.getElementById('addPastPositionModal').showModal()"><i class="fas fa-clock-rotate-left"></i> Add Past Position</button>
            </div>
            @if($assignments->count())
                <ul class="trail-timeline">
                    @foreach($assignments as $a)
                        <li class="trail-entry {{ $a->end_date ? '' : 'trail-active' }}">
                            <div class="trail-period">
                                {{ $a->start_date?->format('M d, Y') ?? '-' }}
                                &ndash;
                                {{ $a->end_date?->format('M d, Y') ?? 'present' }}
                            </div>
                            <div class="trail-card">
                                <div class="trail-title">
                                    @if($a->plantilla)
                                        <a href="{{ route('payroll.plantilla.show', $a->plantilla->id) }}">{{ $a->plantilla->title }}</a>
                                        <span class="sg-badge">SG {{ $a->plantilla->salary_grade }} · Step {{ $a->plantilla->step }}</span>
                                        @if($a->plantilla->item_number)<span class="item-badge">Item {{ $a->plantilla->item_number }}</span>@endif
                                    @else
                                        <span class="text-muted">(position deleted)</span>
                                    @endif
                                    @if(!$a->end_date)
                                        <span class="status-chip status-approved">Active</span>
                                    @endif
                                </div>
                                @if($a->plantilla?->department)
                                    <div class="trail-sub"><i class="fas fa-building" style="margin-right:6px"></i>{{ $a->plantilla->department }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="text-muted" style="margin-top:8px;font-size:0.85rem">
                    History inside HRIS begins with the FY 2026 plantilla baseline. Use <strong>Add Past Position</strong>
                    above to record earlier positions this employee held - they're saved as already-ended entries and
                    won't affect their current position or pay.
                </p>
            @else
                <p class="empty-state">No plantilla assignments recorded for this employee.</p>
            @endif
        </section>

        {{-- Change log --}}
        <section class="payroll-section">
            <h2><i class="fas fa-clock-rotate-left"></i>Change Log</h2>
            @if($activity->count())
                <div class="plantilla-panel overflow-x-auto">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>Detail</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activity as $log)
                                @php $d = $log->details ?? []; @endphp
                                <tr>
                                    <td>{{ $log->created_at->format('M d, Y H:i') }}</td>
                                    <td>{{ ucwords(str_replace('_', ' ', $log->action)) }}</td>
                                    <td>
                                        @if($log->action === 'promotion')
                                            {{ $d['from']['title'] ?? '(none)' }} &rarr; {{ $d['to']['title'] ?? '-' }}
                                            <small class="text-muted">(SG {{ $d['from']['salary_grade'] ?? '?' }} &rarr; SG {{ $d['to']['salary_grade'] ?? '?' }}, effective {{ !empty($d['effective_date']) ? \Illuminate\Support\Carbon::parse($d['effective_date'])->format('M d, Y') : '?' }})</small>
                                        @else
                                            {{ $d['title'] ?? '-' }}
                                            @if(!empty($d['item_number']))<small class="text-muted">(Item {{ $d['item_number'] }})</small>@endif
                                            @if(!empty($d['start_date'])) <small class="text-muted">from {{ \Illuminate\Support\Carbon::parse($d['start_date'])->format('M d, Y') }}</small>@endif
                                            @if(!empty($d['end_date'])) <small class="text-muted">to {{ \Illuminate\Support\Carbon::parse($d['end_date'])->format('M d, Y') }}</small>@endif
                                        @endif
                                    </td>
                                    <td>{{ $log->actor->name ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="empty-state">No logged changes for this employee yet. Actions taken in the plantilla screens will appear here.</p>
            @endif
        </section>
    @else
        <p class="empty-state">Select an employee above to view their service trail.</p>
    @endif
@endsection

@section('modals')
@if($employee)
    {{-- Add Past Position modal --}}
    <dialog id="addPastPositionModal" class="employee-modal">
        <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
            <div>
                <h3 style="margin:0">Add Past Position</h3>
                <span class="record-email">Record a position {{ $employee->last_name ?: $employee->name }} held before joining the current plantilla</span>
            </div>
            <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
        </div>

        <form method="POST" action="{{ route('payroll.plantilla.history.store') }}" class="payroll-form" style="margin-top:12px">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $employee->id }}">
            <div class="form-section-title"><i class="fas fa-id-card"></i>Position Details</div>
            <div class="form-group">
                <label for="pp-title">Position Title</label>
                <input type="text" name="title" id="pp-title" value="{{ old('title') }}" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="pp-dept">Department / Office <small>(optional)</small></label>
                <select name="department" id="pp-dept" class="form-input">
                    <option value="">Select department...</option>
                    @foreach($orgDepartments as $dept)
                        <option value="{{ $dept->Dept_name }}" @selected(old('department') === $dept->Dept_name)>{{ $dept->Dept_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-section-title"><i class="fas fa-sack-dollar"></i>Compensation &amp; Duration</div>
            <div class="form-row">
                <div class="form-group">
                    <label for="pp-sg">Salary Grade (SG)</label>
                    <input type="number" name="salary_grade" id="pp-sg" min="1" max="33" value="{{ old('salary_grade') }}" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="pp-step">Step</label>
                    <input type="number" name="step" id="pp-step" min="1" max="8" value="{{ old('step', 1) }}" class="form-input" required>
                </div>
            </div>
            <div class="form-group">
                <label for="pp-type">Employment Type</label>
                <select name="employment_type" id="pp-type" class="form-input" required>
                    @foreach(['permanent','casual','co-terminus','contractual','job_order','elected_official'] as $t)
                        <option value="{{ $t }}" @selected(old('employment_type') == $t)>{{ ucwords(str_replace('_', ' ', $t)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="pp-start">Start Date</label>
                    <input type="date" name="start_date" id="pp-start" value="{{ old('start_date') }}" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="pp-end">End Date</label>
                    <input type="date" name="end_date" id="pp-end" value="{{ old('end_date') }}" class="form-input" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn"><i class="fas fa-plus"></i> Add to Trail</button>
                <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
            </div>
        </form>
    </dialog>
@endif
@endsection

@section('page_scripts_after')
<script>
@if ($errors->any())
    document.getElementById('addPastPositionModal')?.showModal();
@endif
</script>
@endsection
