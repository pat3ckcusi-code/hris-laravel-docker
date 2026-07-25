@php
    // The current incumbent's own step when filled (personal to their stint);
    // resets to 1 when vacant, since there's no assignment to read a step from.
    $displayStep = $plantilla->assignments->first(fn ($a) => $a->isCurrent())?->step ?? 1;
@endphp

@extends('dashboards.layout', [
    'title' => $plantilla->title,
    'subtitle' => "SG-{$plantilla->salary_grade} Step {$displayStep} · {$plantilla->employment_type}",
])

@section('top_actions')
    <div class="plantilla-actions-group">
        @if($routePrefix === 'payroll')
            @if($plantilla->is_abolished)
                <form method="POST" action="{{ route('payroll.plantilla.restore', $plantilla->id) }}" style="display:inline" id="restore-plantilla-{{ $plantilla->id }}">
                    @csrf
                    <button type="button" class="btn btn-sm" onclick="confirmRestorePlantilla({{ $plantilla->id }})"><i class="fas fa-rotate-left"></i> Restore Position</button>
                </form>
            @else
                <button type="button" class="btn btn-sm" onclick="document.getElementById('assignModal').showModal()"><i class="fas fa-user-plus"></i> Assign Employee</button>
                <form method="POST" action="{{ route('payroll.plantilla.abolish', $plantilla->id) }}" style="display:inline" id="abolish-plantilla-{{ $plantilla->id }}">
                    @csrf
                    <input type="hidden" name="reason" id="abolish-reason-{{ $plantilla->id }}">
                    <button type="button" class="plantilla-icon-btn-danger" onclick="confirmAbolishPlantilla({{ $plantilla->id }})" title="Abolish position"><i class="fas fa-ban"></i></button>
                </form>
            @endif
        @endif
        <a href="{{ route("{$routePrefix}.plantilla.index") }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <div class="detail-card">
        <div class="detail-row"><strong>Position Title:</strong> {{ $plantilla->title }}</div>
        <div class="detail-row"><strong>Item Number:</strong> {{ $plantilla->item_number ?: '-' }}</div>
        <div class="detail-row"><strong>Department / Office:</strong> {{ $plantilla->department ?: '-' }}</div>
        <div class="detail-row"><strong>Salary Grade:</strong> {{ $plantilla->salary_grade }}</div>
        <div class="detail-row"><strong>Step:</strong> {{ $displayStep }}</div>
        <div class="detail-row"><strong>Employment Type:</strong> {{ ucfirst($plantilla->employment_type) }}</div>
        <div class="detail-row"><strong>CSC Eligibility Required:</strong> {{ $eligibilityOptions[$plantilla->csc_eligibility] ?? 'Not specified' }}</div>
        <div class="detail-row"><strong>Active Incumbents:</strong> {{ $plantilla->assignments->filter(fn ($a) => $a->isCurrent())->count() }}</div>
        @if($plantilla->is_abolished)
            <div class="detail-row">
                <strong>Status:</strong>
                <span class="status-chip status-abolished">Abolished</span>
                on {{ $plantilla->abolished_at?->format('M d, Y') }}
                by {{ $plantilla->abolishedBy->name ?? 'Unknown' }}
                @if($plantilla->abolished_reason)
                    &mdash; {{ $plantilla->abolished_reason }}
                @endif
            </div>
        @endif
    </div>

    <section class="payroll-section">
        <h2><i class="fas fa-graduation-cap"></i>Qualification Standards</h2>
        <div class="detail-card">
            <div class="detail-row"><strong>Education:</strong> <span style="white-space:pre-line">{{ $plantilla->education ?: 'Not specified' }}</span></div>
            <div class="detail-row"><strong>Training:</strong> <span style="white-space:pre-line">{{ $plantilla->training ?: 'Not specified' }}</span></div>
            <div class="detail-row"><strong>Work Experience:</strong> <span style="white-space:pre-line">{{ $plantilla->experience ?: 'Not specified' }}</span></div>
            <div class="detail-row"><strong>Competency:</strong> <span style="white-space:pre-line">{{ $plantilla->competency ?: 'Not specified' }}</span></div>
        </div>
    </section>

    <section class="payroll-section">
        <h2>Employee Assignments</h2>
        @if($plantilla->assignments->count())
            <table class="hris-table" id="assignments-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Step</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        @if($routePrefix === 'payroll')
                            <th>Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($plantilla->assignments as $a)
                        <tr id="assign-row-{{ $a->id }}"
                            data-id="{{ $a->id }}"
                            data-start="{{ $a->start_date->format('Y-m-d') }}"
                            data-end="{{ $a->end_date ? $a->end_date->format('Y-m-d') : '' }}"
                            data-step="{{ $a->step }}">
                            <td>{{ $a->employee->name ?? '-' }}</td>
                            <td>{{ $a->step }}</td>
                            <td>{{ $a->start_date->format('M d, Y') }}</td>
                            <td>{{ $a->end_date ? $a->end_date->format('M d, Y') : '-' }}</td>
                            <td>
                                @if($a->isSuperseded())
                                    <span class="status-chip status-locked">Superseded before it took effect</span>
                                @elseif($a->start_date->isFuture())
                                    <span class="status-chip status-draft">Not yet started</span>
                                @elseif($a->isCurrent())
                                    <span class="status-chip status-approved">Active</span>
                                @else
                                    <span class="status-chip status-rejected">Ended</span>
                                @endif
                            </td>
                            @if($routePrefix === 'payroll')
                                <td>
                                    <div class="action-btns">
                                        <button type="button" class="btn btn-sm btn-outline" onclick="openEditAssignment({{ $a->id }})">Edit</button>
                                        <form method="POST" action="{{ route('payroll.plantilla.assignments.destroy', [$plantilla->id, $a->id]) }}" style="display:inline" id="delete-assign-{{ $a->id }}">
                                            @csrf @method('DELETE')
                                            <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeleteAssignment({{ $a->id }})">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No employees assigned to this position.@if($routePrefix === 'payroll') Click <strong>Assign Employee</strong> to add one.@endif</p>
        @endif
    </section>
@endsection

@section('modals')
@if($routePrefix === 'payroll')
{{-- Assign Employee Modal --}}
<dialog id="assignModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Assign Employee</h3>
            <span class="record-email">Link an employee to {{ $plantilla->title }}</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.plantilla.assignments.store', $plantilla->id) }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="assign-employee">Employee</label>
            <select name="employee_id" id="assign-employee" class="form-input" required>
                <option value="">Select employee</option>
                @foreach($employees as $emp)
                    @php($current = $currentAssignments->get($emp->id))
                    <option value="{{ $emp->id }}" @selected(old('employee_id') == $emp->id)>
                        {{ $emp->last_name ? "{$emp->last_name}, {$emp->first_name}" : $emp->name }}{{ $emp->EmpNo ? " ({$emp->EmpNo})" : '' }}{{ $emp->designation ? " -{$emp->designation}" : '' }}{{ $current ? ' • currently: '.($current->plantilla->title ?? 'assigned') : '' }}
                    </option>
                @endforeach
            </select>
            <small class="text-muted">Employees marked "currently: ..." already hold an active assignment; assigning them here will end that assignment automatically.</small>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="assign-start">Start Date</label>
                <input type="date" name="start_date" id="assign-start" value="{{ old('start_date', date('Y-m-d')) }}" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="assign-end">End Date <small>(optional)</small></label>
                <input type="date" name="end_date" id="assign-end" value="{{ old('end_date') }}" class="form-input">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Assign</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Edit Assignment Modal --}}
<dialog id="editAssignModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Assignment</h3>
            <span class="record-email" id="edit-assign-subtitle">Update dates</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" id="editAssignForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <input type="hidden" name="assignment_id" id="edit-assignment-id" value="{{ old('assignment_id') }}">
        <div class="form-row">
            <div class="form-group">
                <label for="edit-start">Start Date</label>
                <input type="date" name="start_date" id="edit-start" value="{{ old('start_date') }}" class="form-input" required>
                @error('start_date', 'editAssignment') <div class="text-danger" style="font-size:0.85rem;margin-top:4px">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label for="edit-end">End Date <small>(optional)</small></label>
                <input type="date" name="end_date" id="edit-end" value="{{ old('end_date') }}" class="form-input">
                @error('end_date', 'editAssignment') <div class="text-danger" style="font-size:0.85rem;margin-top:4px">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-group">
            <label for="edit-step">Step</label>
            <input type="number" name="step" id="edit-step" value="{{ old('step') }}" min="1" max="8" class="form-input" required>
            <small class="text-muted">This employee's own step for this assignment (e.g. granting a step increment) - separate from the position's own catalog step shown in Edit Position.</small>
            @error('step', 'editAssignment') <div class="text-danger" style="font-size:0.85rem;margin-top:4px">{{ $message }}</div> @enderror
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endif
@endsection

@section('page_scripts_after')
<script>
function openEditAssignment(id) {
    var row = document.getElementById('assign-row-' + id);
    if (!row) return;
    document.getElementById('editAssignForm').action = '{{ url("payroll-manager/plantilla") }}/{{ $plantilla->id }}/assignments/' + id;
    document.getElementById('edit-assignment-id').value = id;
    document.getElementById('edit-start').value = row.dataset.start;
    document.getElementById('edit-end').value = row.dataset.end;
    document.getElementById('edit-step').value = row.dataset.step;
    document.getElementById('edit-assign-subtitle').textContent = 'Assignment #' + id;
    document.getElementById('editAssignModal').showModal();
}

function confirmDeleteAssignment(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Remove this assignment?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-assign-' + id).submit(); });
    } else if (confirm('Remove this assignment?')) {
        document.getElementById('delete-assign-' + id).submit();
    }
}

function confirmAbolishPlantilla(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Abolish this position?',
            text: 'It will no longer be available for assignment, but its history will remain.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Abolish',
            input: 'textarea', inputPlaceholder: 'Reason (optional)',
        }).then(function(r) {
            if (r.isConfirmed) {
                document.getElementById('abolish-reason-' + id).value = r.value || '';
                document.getElementById('abolish-plantilla-' + id).submit();
            }
        });
    } else if (confirm('Abolish this position? It will no longer be available for assignment, but its history will remain.')) {
        document.getElementById('abolish-plantilla-' + id).submit();
    }
}

function confirmRestorePlantilla(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Restore this position?', text: 'It will become available for assignment again.', icon: 'question', showCancelButton: true, confirmButtonText: 'Restore' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('restore-plantilla-' + id).submit(); });
    } else if (confirm('Restore this position?')) {
        document.getElementById('restore-plantilla-' + id).submit();
    }
}

@if ($errors->any())
    document.getElementById('assignModal').showModal();
@endif
@if ($errors->editAssignment->any())
    (function() {
        var id = document.getElementById('edit-assignment-id').value;
        if (id) {
            document.getElementById('editAssignForm').action = '{{ url("payroll-manager/plantilla") }}/{{ $plantilla->id }}/assignments/' + id;
            document.getElementById('edit-assign-subtitle').textContent = 'Assignment #' + id;
        }
        document.getElementById('editAssignModal').showModal();
    })();
@endif
</script>
@endsection
