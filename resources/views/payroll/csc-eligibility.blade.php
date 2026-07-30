@extends('dashboards.layout', [
    'title' => 'CSC Eligibility',
    'subtitle' => 'Manage the CSC Eligibility categories offered on plantilla positions.',
])

@section('top_actions')
    <a href="{{ route('payroll.plantilla.index') }}" class="btn btn-sm btn-outline">Back</a>
    <button type="button" class="btn btn-sm" onclick="document.getElementById('createEligibilityModal').showModal()"><i class="fas fa-plus"></i> Add Category</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$eligibilityOptions">
        <table class="hris-table" id="eligibility-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Key</th>
                    <th>Positions Using This</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($eligibilityOptions as $o)
                    <tr id="eligibility-row-{{ $o->id }}"
                        data-id="{{ $o->id }}"
                        data-label="{{ $o->label }}"
                        data-key="{{ $o->key }}">
                        <td>{{ $o->label }}</td>
                        <td><code>{{ $o->key }}</code></td>
                        <td>{{ $o->plantillas_count }} position(s)</td>
                        <td>
                            <div class="action-btns">
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditEligibility({{ $o->id }})">Edit</button>
                                <form method="POST" action="{{ route('payroll.csc-eligibility.destroy', $o->id) }}" style="display:inline" id="delete-eligibility-{{ $o->id }}">
                                    @csrf @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteEligibility({{ $o->id }})">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">No CSC Eligibility categories defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
{{-- Create Eligibility Modal --}}
<dialog id="createEligibilityModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-certificate"></i></span>
            <div>
                <h3>Add CSC Eligibility Category</h3>
                <p class="modal-subtitle">Define a new eligibility category for plantilla positions</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.csc-eligibility.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-eligibility-label"><i class="fas fa-tag"></i> Label</label>
            <input type="text" name="label" id="c-eligibility-label" value="{{ old('label') }}" class="form-input" required maxlength="150" placeholder="e.g. Career Service Professional (2nd Level)">
            @error('label') <div class="text-danger" style="font-size:0.85rem;margin-top:4px">{{ $message }}</div> @enderror
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-plus"></i> Save</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Edit Eligibility Modal --}}
<dialog id="editEligibilityModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-pen"></i></span>
            <div>
                <h3>Edit CSC Eligibility Category</h3>
                <p class="modal-subtitle" id="edit-eligibility-subtitle">Update category</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" id="editEligibilityForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-group">
            <label for="e-eligibility-label"><i class="fas fa-tag"></i> Label</label>
            <input type="text" name="label" id="e-eligibility-label" class="form-input" required maxlength="150">
        </div>
        <p class="record-email">Machine value: <code id="e-eligibility-key-display"></code> (cannot be changed)</p>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openEditEligibility(id) {
    var row = document.getElementById('eligibility-row-' + id);
    if (!row) return;
    document.getElementById('editEligibilityForm').action = '{{ url("payroll-manager/csc-eligibility") }}/' + id;
    document.getElementById('e-eligibility-label').value = row.dataset.label;
    document.getElementById('e-eligibility-key-display').textContent = row.dataset.key;
    document.getElementById('edit-eligibility-subtitle').textContent = row.dataset.label;
    document.getElementById('editEligibilityModal').showModal();
}
function confirmDeleteEligibility(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete this category?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-eligibility-' + id).submit(); });
    } else if (confirm('Delete?')) { document.getElementById('delete-eligibility-' + id).submit(); }
}
@if ($errors->any()) document.getElementById('createEligibilityModal').showModal(); @endif
</script>
@endsection
