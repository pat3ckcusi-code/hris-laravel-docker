@extends('dashboards.layout', [
    'title' => 'Exceptions & Validation',
    'subtitle' => 'Flag and resolve payroll discrepancies.',
])

@section('top_actions')
    <div class="header-actions">
        <form method="GET" action="{{ route('payroll.exceptions.index') }}" class="filter-form" style="display:inline-flex;gap:8px;">
            <select name="resolved" class="form-input">
                <option value="">All</option>
                <option value="0" @selected(request('resolved') === '0')>Unresolved</option>
                <option value="1" @selected(request('resolved') === '1')>Resolved</option>
            </select>
            <button type="submit" class="btn btn-sm">Filter</button>
        </form>
        <button type="button" class="btn btn-sm" onclick="document.getElementById('createExceptionModal').showModal()"><i class="fas fa-plus"></i> Log Exception</button>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$exceptions">
        <table class="hris-table" id="exceptions-table">
            <thead>
                <tr>
                    <th>Payroll Run</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($exceptions as $ex)
                    <tr id="exception-row-{{ $ex->id }}"
                        data-id="{{ $ex->id }}"
                        data-type="{{ $ex->type }}"
                        data-description="{{ $ex->description ?? '' }}"
                        data-resolved="{{ $ex->resolved_flag ? '1' : '0' }}"
                        data-run="{{ $ex->payroll_run_id }}"
                        data-run-period="{{ $ex->payrollRun->period ?? '' }}">
                        <td>Run #{{ $ex->payroll_run_id }} - {{ $ex->payrollRun->period ?? '' }}</td>
                        <td>{{ $ex->type }}</td>
                        <td>{{ Str::limit($ex->description, 80) }}</td>
                        <td>
                            @if($ex->resolved_flag)
                                <span class="status-chip status-approved">Resolved</span>
                            @else
                                <span class="status-chip status-draft">Unresolved</span>
                            @endif
                        </td>
                        <td>
                            <div class="action-btns">
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openShowException({{ $ex->id }})">View</button>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditException({{ $ex->id }})">Edit</button>
                                <form method="POST" action="{{ route('payroll.exceptions.destroy', $ex->id) }}" style="display:inline" id="delete-exception-{{ $ex->id }}">
                                    @csrf @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteException({{ $ex->id }})">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">No exceptions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
<dialog id="createExceptionModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Log Exception</h3>
            <span class="record-email">Report a payroll-related exception</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.exceptions.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-run">Payroll Run</label>
            <select name="payroll_run_id" id="c-run" class="form-input" required>
                <option value="">Select run</option>
                @foreach($runs as $run)
                    <option value="{{ $run->id }}" @selected(old('payroll_run_id') == $run->id)>Run #{{ $run->id }} - {{ $run->period }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="c-type">Exception Type</label>
            <input type="text" name="type" id="c-type" value="{{ old('type') }}" class="form-input" required placeholder="e.g. Missing DTR, Salary Mismatch">
        </div>
        <div class="form-group">
            <label for="c-desc">Description</label>
            <textarea name="description" id="c-desc" class="form-input" rows="4">{{ old('description') }}</textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Submit</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="editExceptionModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Exception</h3>
            <span class="record-email" id="edit-exception-subtitle">Update exception</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" id="editExceptionForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-group">
            <label for="e-type">Exception Type</label>
            <input type="text" name="type" id="e-type" class="form-input" required>
        </div>
        <div class="form-group">
            <label for="e-desc">Description</label>
            <textarea name="description" id="e-desc" class="form-input" rows="4"></textarea>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="resolved_flag" value="0">
                <input type="checkbox" name="resolved_flag" value="1" id="e-resolved"> Mark as Resolved
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="showExceptionModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div><h3 style="margin:0">Exception Details</h3></div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <div id="showExceptionBody" style="margin-top:12px"></div>
    <form method="dialog" class="form-actions" style="margin-top:12px;text-align:right">
        <button class="btn btn-outline" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openEditException(id) {
    var row = document.getElementById('exception-row-' + id);
    if (!row) return;
    document.getElementById('editExceptionForm').action = '{{ url("payroll-manager/exceptions") }}/' + id;
    document.getElementById('e-type').value = row.dataset.type;
    document.getElementById('e-desc').value = row.dataset.description;
    document.getElementById('e-resolved').checked = row.dataset.resolved === '1';
    document.getElementById('edit-exception-subtitle').textContent = row.dataset.type;
    document.getElementById('editExceptionModal').showModal();
}
function openShowException(id) {
    var row = document.getElementById('exception-row-' + id);
    if (!row) return;
    var resolved = row.dataset.resolved === '1' ? 'Resolved' : 'Unresolved';
    document.getElementById('showExceptionBody').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Payroll Run</strong></td><td style="padding:8px;border:1px solid #f1f5f9">Run #' + row.dataset.run + ' - ' + row.dataset.runPeriod + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.type + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Description</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + (row.dataset.description || '-') + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Status</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + resolved + '</td></tr>' +
        '</tbody></table>';
    document.getElementById('showExceptionModal').showModal();
}
function confirmDeleteException(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-exception-' + id).submit(); });
    } else if (confirm('Delete?')) { document.getElementById('delete-exception-' + id).submit(); }
}
@if ($errors->any()) document.getElementById('createExceptionModal').showModal(); @endif
</script>
@endsection
