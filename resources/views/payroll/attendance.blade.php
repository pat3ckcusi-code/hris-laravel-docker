@extends('dashboards.layout', [
    'title' => 'Attendance / DTR',
    'subtitle' => 'Daily Time Records for payroll computation.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="openCreateDtr()"><i class="fas fa-plus"></i> Add DTR Entry</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$records">
        <x-slot:filters>
            <form method="GET" action="{{ route('payroll.attendance.index') }}" class="filter-form" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <select name="employee_id" class="hris-filter-select">
                    <option value="">All Employees</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" @selected(request('employee_id') == $emp->id)>{{ $emp->name }}</option>
                    @endforeach
                </select>
                <input type="date" name="date" value="{{ request('date') }}" class="hris-filter-select">
                <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm">Filter</button>
            </form>
        </x-slot:filters>

        <table class="hris-table" id="dtr-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>AM In</th>
                    <th>AM Out</th>
                    <th>PM In</th>
                    <th>PM Out</th>
                    <th>Late</th>
                    <th>UT</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $rec)
                    <tr id="dtr-row-{{ $rec->id }}"
                        data-id="{{ $rec->id }}"
                        data-employee="{{ $rec->employee->name ?? '-' }}"
                        data-date="{{ $rec->date->format('M d, Y') }}"
                        data-time-in-am="{{ $rec->time_in_am ?? '-' }}"
                        data-time-out-am="{{ $rec->time_out_am ?? '-' }}"
                        data-time-in-pm="{{ $rec->time_in_pm ?? '-' }}"
                        data-time-out-pm="{{ $rec->time_out_pm ?? '-' }}"
                        data-late="{{ $rec->late_minutes ?? 0 }}"
                        data-undertime="{{ $rec->undertime_minutes ?? 0 }}"
                        data-is-absent="{{ $rec->is_absent ? '1' : '0' }}"
                        data-status="{{ $rec->status }}"
                        data-time-in-am-raw="{{ $rec->time_in_am ?? '' }}"
                        data-time-out-am-raw="{{ $rec->time_out_am ?? '' }}"
                        data-time-in-pm-raw="{{ $rec->time_in_pm ?? '' }}"
                        data-time-out-pm-raw="{{ $rec->time_out_pm ?? '' }}">
                        <td>{{ $rec->employee->name ?? '-' }}</td>
                        <td>{{ $rec->date->format('M d, Y') }}</td>
                        <td>{{ $rec->time_in_am ?? '-' }}</td>
                        <td>{{ $rec->time_out_am ?? '-' }}</td>
                        <td>{{ $rec->time_in_pm ?? '-' }}</td>
                        <td>{{ $rec->time_out_pm ?? '-' }}</td>
                        <td>{{ $rec->late_minutes ?? 0 }}m</td>
                        <td>{{ $rec->undertime_minutes ?? 0 }}m</td>
                        <td><span class="status-chip">{{ ucfirst($rec->status) }}</span></td>
                        <td>
                            <div class="action-btns">
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openShowDtr({{ $rec->id }})">View</button>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditDtr({{ $rec->id }})">Edit</button>
                                <form method="POST" action="{{ route('payroll.attendance.destroy', $rec->id) }}" style="display:inline" id="delete-dtr-{{ $rec->id }}">
                                    @csrf @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteDtr({{ $rec->id }})">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted">No DTR records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
{{-- Create DTR Modal --}}
<dialog id="createDtrModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Add DTR Entry</h3>
            <span class="record-email">Record a new attendance entry</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.attendance.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="create-employee_id">Employee</label>
            <select name="employee_id" id="create-employee_id" class="form-input" required>
                <option value="">Select employee</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}" @selected(old('employee_id') == $emp->id)>{{ $emp->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="create-date">Date</label>
            <input type="date" name="date" id="create-date" value="{{ old('date') }}" class="form-input" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="create-time_in_am">AM In</label>
                <input type="time" name="time_in_am" id="create-time_in_am" value="{{ old('time_in_am') }}" class="form-input">
            </div>
            <div class="form-group">
                <label for="create-time_out_am">AM Out</label>
                <input type="time" name="time_out_am" id="create-time_out_am" value="{{ old('time_out_am') }}" class="form-input">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="create-time_in_pm">PM In</label>
                <input type="time" name="time_in_pm" id="create-time_in_pm" value="{{ old('time_in_pm') }}" class="form-input">
            </div>
            <div class="form-group">
                <label for="create-time_out_pm">PM Out</label>
                <input type="time" name="time_out_pm" id="create-time_out_pm" value="{{ old('time_out_pm') }}" class="form-input">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="create-late_minutes">Late (min)</label>
                <input type="number" name="late_minutes" id="create-late_minutes" value="{{ old('late_minutes', 0) }}" min="0" class="form-input">
            </div>
            <div class="form-group">
                <label for="create-undertime_minutes">Undertime (min)</label>
                <input type="number" name="undertime_minutes" id="create-undertime_minutes" value="{{ old('undertime_minutes', 0) }}" min="0" class="form-input">
            </div>
        </div>
        <div class="form-group">
            <label for="create-status">Status</label>
            <select name="status" id="create-status" class="form-input" required>
                <option value="present" @selected(old('status') == 'present')>Present</option>
                <option value="absent" @selected(old('status') == 'absent')>Absent</option>
                <option value="late" @selected(old('status') == 'late')>Late</option>
                <option value="undertime" @selected(old('status') == 'undertime')>Undertime</option>
                <option value="on_leave" @selected(old('status') == 'on_leave')>On Leave</option>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save DTR</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Edit DTR Modal --}}
<dialog id="editDtrModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit DTR Entry</h3>
            <span class="record-email" id="edit-dtr-subtitle">Update attendance record</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" id="editDtrForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-row">
            <div class="form-group">
                <label for="edit-time_in_am">AM In</label>
                <input type="time" name="time_in_am" id="edit-time_in_am" class="form-input">
            </div>
            <div class="form-group">
                <label for="edit-time_out_am">AM Out</label>
                <input type="time" name="time_out_am" id="edit-time_out_am" class="form-input">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit-time_in_pm">PM In</label>
                <input type="time" name="time_in_pm" id="edit-time_in_pm" class="form-input">
            </div>
            <div class="form-group">
                <label for="edit-time_out_pm">PM Out</label>
                <input type="time" name="time_out_pm" id="edit-time_out_pm" class="form-input">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit-late_minutes">Late (min)</label>
                <input type="number" name="late_minutes" id="edit-late_minutes" min="0" class="form-input">
            </div>
            <div class="form-group">
                <label for="edit-undertime_minutes">Undertime (min)</label>
                <input type="number" name="undertime_minutes" id="edit-undertime_minutes" min="0" class="form-input">
            </div>
        </div>
        <div class="form-group">
            <label for="edit-status">Status</label>
            <select name="status" id="edit-status" class="form-input" required>
                <option value="present">Present</option>
                <option value="absent">Absent</option>
                <option value="late">Late</option>
                <option value="undertime">Undertime</option>
                <option value="on_leave">On Leave</option>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Show DTR Modal --}}
<dialog id="showDtrModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">DTR Details</h3>
            <span class="record-email">Viewing attendance record</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <div id="showDtrBody" style="margin-top:12px"></div>
    <form method="dialog" class="form-actions" style="margin-top:12px;text-align:right">
        <button class="btn btn-outline" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openCreateDtr() { document.getElementById('createDtrModal').showModal(); }

function openEditDtr(id) {
    var row = document.getElementById('dtr-row-' + id);
    if (!row) return;
    document.getElementById('editDtrForm').action = '{{ url("payroll-manager/attendance") }}/' + id;
    document.getElementById('edit-time_in_am').value = row.dataset.timeInAmRaw;
    document.getElementById('edit-time_out_am').value = row.dataset.timeOutAmRaw;
    document.getElementById('edit-time_in_pm').value = row.dataset.timeInPmRaw;
    document.getElementById('edit-time_out_pm').value = row.dataset.timeOutPmRaw;
    document.getElementById('edit-late_minutes').value = row.dataset.late;
    document.getElementById('edit-undertime_minutes').value = row.dataset.undertime;
    document.getElementById('edit-status').value = row.dataset.status;
    document.getElementById('edit-dtr-subtitle').textContent = 'Record #' + id;
    document.getElementById('editDtrModal').showModal();
}

function openShowDtr(id) {
    var row = document.getElementById('dtr-row-' + id);
    if (!row) return;
    document.getElementById('showDtrBody').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.employee + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Date</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.date + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>AM In</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.timeInAm + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>AM Out</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.timeOutAm + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>PM In</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.timeInPm + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>PM Out</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.timeOutPm + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Late</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.late + ' min</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Undertime</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.undertime + ' min</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Status</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.status.charAt(0).toUpperCase() + row.dataset.status.slice(1) + '</td></tr>' +
        '</tbody></table>';
    document.getElementById('showDtrModal').showModal();
}

function confirmDeleteDtr(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete this record?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-dtr-' + id).submit(); });
    } else if (confirm('Delete this record?')) {
        document.getElementById('delete-dtr-' + id).submit();
    }
}

@if ($errors->any())
    document.getElementById('createDtrModal').showModal();
@endif
</script>
@endsection
