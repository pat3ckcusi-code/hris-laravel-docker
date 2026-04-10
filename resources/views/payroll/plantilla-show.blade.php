@extends('dashboards.layout', [
    'title' => $plantilla->title,
    'subtitle' => "SG-{$plantilla->salary_grade} Step {$plantilla->step} · {$plantilla->employment_type}",
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('assignModal').showModal()"><i class="fas fa-user-plus"></i> Assign Employee</button>
    <a href="{{ route('payroll.plantilla.index') }}" class="btn btn-sm btn-outline">Back</a>
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
        <div class="detail-row"><strong>Salary Grade:</strong> {{ $plantilla->salary_grade }}</div>
        <div class="detail-row"><strong>Step:</strong> {{ $plantilla->step }}</div>
        <div class="detail-row"><strong>Employment Type:</strong> {{ ucfirst($plantilla->employment_type) }}</div>
        <div class="detail-row"><strong>Assigned Employees:</strong> {{ $plantilla->assignments->count() }}</div>
    </div>

    <section class="payroll-section">
        <h2>Employee Assignments</h2>
        @if($plantilla->assignments->count())
            <table class="payroll-table" id="assignments-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($plantilla->assignments as $a)
                        <tr id="assign-row-{{ $a->id }}"
                            data-id="{{ $a->id }}"
                            data-start="{{ $a->start_date->format('Y-m-d') }}"
                            data-end="{{ $a->end_date ? $a->end_date->format('Y-m-d') : '' }}">
                            <td>{{ $a->employee->name ?? '—' }}</td>
                            <td>{{ $a->start_date->format('M d, Y') }}</td>
                            <td>{{ $a->end_date ? $a->end_date->format('M d, Y') : '—' }}</td>
                            <td>
                                @if(!$a->end_date || $a->end_date->isFuture())
                                    <span class="status-chip status-approved">Active</span>
                                @else
                                    <span class="status-chip status-rejected">Ended</span>
                                @endif
                            </td>
                            <td>
                                <div class="action-btns">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="openEditAssignment({{ $a->id }})">Edit</button>
                                    <form method="POST" action="{{ route('payroll.plantilla.assignments.destroy', [$plantilla->id, $a->id]) }}" style="display:inline" id="delete-assign-{{ $a->id }}">
                                        @csrf @method('DELETE')
                                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeleteAssignment({{ $a->id }})">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No employees assigned to this position. Click <strong>Assign Employee</strong> to add one.</p>
        @endif
    </section>
@endsection

@section('modals')
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
                    <option value="{{ $emp->id }}" @selected(old('employee_id') == $emp->id)>{{ $emp->name }}</option>
                @endforeach
            </select>
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
        <div class="form-row">
            <div class="form-group">
                <label for="edit-start">Start Date</label>
                <input type="date" name="start_date" id="edit-start" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="edit-end">End Date <small>(optional)</small></label>
                <input type="date" name="end_date" id="edit-end" class="form-input">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openEditAssignment(id) {
    var row = document.getElementById('assign-row-' + id);
    if (!row) return;
    document.getElementById('editAssignForm').action = '{{ url("payroll-manager/plantilla") }}/{{ $plantilla->id }}/assignments/' + id;
    document.getElementById('edit-start').value = row.dataset.start;
    document.getElementById('edit-end').value = row.dataset.end;
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

@if ($errors->any())
    document.getElementById('assignModal').showModal();
@endif
</script>
@endsection
