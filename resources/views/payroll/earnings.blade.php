@extends('dashboards.layout', [
    'title' => 'Earnings (Allowances)',
    'subtitle' => 'Manage earning types and employee allowances.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('createEarningModal').showModal()"><i class="fas fa-plus"></i> Add Earning Type</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$earningTypes">
        <table class="hris-table" id="earnings-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Recurring</th>
                    <th>Assigned To</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($earningTypes as $e)
                    <tr id="earning-row-{{ $e->id }}"
                        data-id="{{ $e->id }}"
                        data-type="{{ $e->type }}"
                        data-description="{{ $e->description ?? '' }}"
                        data-recurring="{{ $e->recurring ? '1' : '0' }}">
                        <td>{{ $e->type }}</td>
                        <td>{{ $e->description ?? '-' }}</td>
                        <td>{{ $e->recurring ? 'Yes' : 'No' }}</td>
                        <td>{{ $e->employee_earnings_count }} employees</td>
                        <td>
                            <div class="action-btns">
                                <a href="{{ route('payroll.earnings.show', $e->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">View</a>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditEarning({{ $e->id }})">Edit</button>
                                <form method="POST" action="{{ route('payroll.earnings.destroy', $e->id) }}" style="display:inline" id="delete-earning-{{ $e->id }}">
                                    @csrf @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteEarning({{ $e->id }})">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">No earning types defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
{{-- Create Earning Modal --}}
<dialog id="createEarningModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Add Earning Type</h3>
            <span class="record-email">Define a new earning or allowance type</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.earnings.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-type">Type / Name</label>
            <input type="text" name="type" id="c-type" value="{{ old('type') }}" class="form-input" required placeholder="e.g. PERA, LCA, Hazard Pay">
        </div>
        <div class="form-group">
            <label for="c-desc">Description</label>
            <textarea name="description" id="c-desc" class="form-input" rows="3">{{ old('description') }}</textarea>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="recurring" value="0">
                <input type="checkbox" name="recurring" value="1" @checked(old('recurring'))> Recurring (monthly)
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Edit Earning Modal --}}
<dialog id="editEarningModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Earning Type</h3>
            <span class="record-email" id="edit-earning-subtitle">Update earning</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" id="editEarningForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-group">
            <label for="e-type">Type / Name</label>
            <input type="text" name="type" id="e-type" class="form-input" required>
        </div>
        <div class="form-group">
            <label for="e-desc">Description</label>
            <textarea name="description" id="e-desc" class="form-input" rows="3"></textarea>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="recurring" value="0">
                <input type="checkbox" name="recurring" value="1" id="e-recurring"> Recurring (monthly)
            </label>
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
function openEditEarning(id) {
    var row = document.getElementById('earning-row-' + id);
    if (!row) return;
    document.getElementById('editEarningForm').action = '{{ url("payroll-manager/earnings") }}/' + id;
    document.getElementById('e-type').value = row.dataset.type;
    document.getElementById('e-desc').value = row.dataset.description;
    document.getElementById('e-recurring').checked = row.dataset.recurring === '1';
    document.getElementById('edit-earning-subtitle').textContent = row.dataset.type;
    document.getElementById('editEarningModal').showModal();
}
function confirmDeleteEarning(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-earning-' + id).submit(); });
    } else if (confirm('Delete?')) { document.getElementById('delete-earning-' + id).submit(); }
}
@if ($errors->any()) document.getElementById('createEarningModal').showModal(); @endif
</script>
@endsection
