@extends('dashboards.layout', [
    'title' => 'Deductions',
    'subtitle' => 'Manage deduction types, employee deductions, and loans.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('createDeductionModal').showModal()"><i class="fas fa-plus"></i> Add Deduction Type</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$deductionTypes">
        <table class="hris-table" id="deductions-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Formula</th>
                    <th>Employees</th>
                    <th>Loans</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deductionTypes as $d)
                    <tr id="deduction-row-{{ $d->id }}"
                        data-id="{{ $d->id }}"
                        data-type="{{ $d->type }}"
                        data-description="{{ $d->description ?? '' }}"
                        data-formula="{{ $d->formula ?? '' }}">
                        <td>{{ $d->type }}</td>
                        <td>{{ $d->description ?? '-' }}</td>
                        <td>{{ $d->formula ?? '-' }}</td>
                        <td>{{ $d->employee_deductions_count }}</td>
                        <td>{{ $d->loans_count }}</td>
                        <td>
                            <div class="action-btns">
                                <a href="{{ route('payroll.deductions.show', $d->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">View</a>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditDeduction({{ $d->id }})">Edit</button>
                                <form method="POST" action="{{ route('payroll.deductions.destroy', $d->id) }}" style="display:inline" id="delete-deduction-{{ $d->id }}">
                                    @csrf @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteDeduction({{ $d->id }})">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No deduction types defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
<dialog id="createDeductionModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Add Deduction Type</h3>
            <span class="record-email">Define a new deduction category</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.deductions.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-type">Type / Name</label>
            <input type="text" name="type" id="c-type" value="{{ old('type') }}" class="form-input" required placeholder="e.g. GSIS, PhilHealth, Pag-IBIG">
        </div>
        <div class="form-group">
            <label for="c-desc">Description</label>
            <textarea name="description" id="c-desc" class="form-input" rows="3">{{ old('description') }}</textarea>
        </div>
        <div class="form-group">
            <label for="c-formula">Formula / Notes</label>
            <input type="text" name="formula" id="c-formula" value="{{ old('formula') }}" class="form-input" placeholder="Optional computation formula">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="editDeductionModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Deduction Type</h3>
            <span class="record-email" id="edit-deduction-subtitle">Update deduction</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" id="editDeductionForm" class="payroll-form" style="margin-top:12px">
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
            <label for="e-formula">Formula / Notes</label>
            <input type="text" name="formula" id="e-formula" class="form-input">
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
function openEditDeduction(id) {
    var row = document.getElementById('deduction-row-' + id);
    if (!row) return;
    document.getElementById('editDeductionForm').action = '{{ url("payroll-manager/deductions") }}/' + id;
    document.getElementById('e-type').value = row.dataset.type;
    document.getElementById('e-desc').value = row.dataset.description;
    document.getElementById('e-formula').value = row.dataset.formula;
    document.getElementById('edit-deduction-subtitle').textContent = row.dataset.type;
    document.getElementById('editDeductionModal').showModal();
}
function confirmDeleteDeduction(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-deduction-' + id).submit(); });
    } else if (confirm('Delete?')) { document.getElementById('delete-deduction-' + id).submit(); }
}
@if ($errors->any()) document.getElementById('createDeductionModal').showModal(); @endif
</script>
@endsection
