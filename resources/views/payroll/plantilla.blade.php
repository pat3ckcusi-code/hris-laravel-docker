@extends('dashboards.layout', [
    'title' => 'Plantilla & Salary',
    'subtitle' => 'Manage position titles, salary grades, and assignments.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="openCreatePlantilla()"><i class="fas fa-plus"></i> Add Position</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$plantillas">
        <table class="hris-table" id="plantilla-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>SG</th>
                    <th>Step</th>
                    <th>Type</th>
                    <th>Assigned</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($plantillas as $p)
                    <tr id="plantilla-row-{{ $p->id }}"
                        data-id="{{ $p->id }}"
                        data-title="{{ $p->title }}"
                        data-sg="{{ $p->salary_grade }}"
                        data-step="{{ $p->step }}"
                        data-type="{{ $p->employment_type }}"
                        data-assigned="{{ $p->assignments->count() }}">
                        <td>{{ $p->title }}</td>
                        <td>{{ $p->salary_grade }}</td>
                        <td>{{ $p->step }}</td>
                        <td>{{ ucfirst($p->employment_type) }}</td>
                        <td>{{ $p->assignments->count() }}</td>
                        <td>
                            <div class="action-btns">
                                <a href="{{ route('payroll.plantilla.show', $p->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">View</a>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditPlantilla({{ $p->id }})">Edit</button>
                                <form method="POST" action="{{ route('payroll.plantilla.destroy', $p->id) }}" style="display:inline" id="delete-plantilla-{{ $p->id }}">
                                    @csrf @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeletePlantilla({{ $p->id }})">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No plantilla positions defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
{{-- Create Plantilla Modal --}}
<dialog id="createPlantillaModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Add Plantilla Position</h3>
            <span class="record-email">Define a new position</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.plantilla.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-title">Position Title</label>
            <input type="text" name="title" id="c-title" value="{{ old('title') }}" class="form-input" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="c-sg">Salary Grade (SG)</label>
                <input type="number" name="salary_grade" id="c-sg" min="1" max="33" value="{{ old('salary_grade') }}" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="c-step">Step</label>
                <input type="number" name="step" id="c-step" min="1" max="8" value="{{ old('step', 1) }}" class="form-input" required>
            </div>
        </div>
        <div class="form-group">
            <label for="c-type">Employment Type</label>
            <select name="employment_type" id="c-type" class="form-input" required>
                @foreach(['permanent','casual','co-terminus','contractual','job_order','elected_official'] as $t)
                    <option value="{{ $t }}" @selected(old('employment_type') == $t)>{{ ucwords(str_replace('_', ' ', $t)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save Position</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Edit Plantilla Modal --}}
<dialog id="editPlantillaModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Plantilla Position</h3>
            <span class="record-email" id="edit-plantilla-subtitle">Update position</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" id="editPlantillaForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-group">
            <label for="e-title">Position Title</label>
            <input type="text" name="title" id="e-title" class="form-input" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="e-sg">Salary Grade (SG)</label>
                <input type="number" name="salary_grade" id="e-sg" min="1" max="33" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="e-step">Step</label>
                <input type="number" name="step" id="e-step" min="1" max="8" class="form-input" required>
            </div>
        </div>
        <div class="form-group">
            <label for="e-type">Employment Type</label>
            <select name="employment_type" id="e-type" class="form-input" required>
                @foreach(['permanent','casual','co-terminus','contractual','job_order','elected_official'] as $t)
                    <option value="{{ $t }}">{{ ucwords(str_replace('_', ' ', $t)) }}</option>
                @endforeach
            </select>
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
function openCreatePlantilla() { document.getElementById('createPlantillaModal').showModal(); }

function openEditPlantilla(id) {
    var row = document.getElementById('plantilla-row-' + id);
    if (!row) return;
    document.getElementById('editPlantillaForm').action = '{{ url("payroll-manager/plantilla") }}/' + id;
    document.getElementById('e-title').value = row.dataset.title;
    document.getElementById('e-sg').value = row.dataset.sg;
    document.getElementById('e-step').value = row.dataset.step;
    document.getElementById('e-type').value = row.dataset.type;
    document.getElementById('edit-plantilla-subtitle').textContent = row.dataset.title;
    document.getElementById('editPlantillaModal').showModal();
}

function confirmDeletePlantilla(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete this position?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-plantilla-' + id).submit(); });
    } else if (confirm('Delete?')) {
        document.getElementById('delete-plantilla-' + id).submit();
    }
}

@if ($errors->any())
    document.getElementById('createPlantillaModal').showModal();
@endif
</script>
@endsection
