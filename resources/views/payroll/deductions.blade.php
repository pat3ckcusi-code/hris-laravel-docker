@extends('dashboards.layout', [
    'title' => 'Deductions',
    'subtitle' => 'Manage mandatory deductions and other recurring deduction types.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('createDeductionModal').showModal()"><i class="fas fa-plus"></i> Add Deduction Type</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <div class="plantilla-stats">
        <div class="stat-tile stat-info">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-label">Total Deduction Types</div>
            </div>
        </div>
        <div class="stat-tile stat-promo">
            <div class="stat-icon"><i class="fas fa-landmark"></i></div>
            <div>
                <div class="stat-value">{{ $stats['mandatory'] }}</div>
                <div class="stat-label">Mandatory (System)</div>
            </div>
        </div>
        <div class="stat-tile">
            <div class="stat-icon"><i class="fas fa-receipt"></i></div>
            <div>
                <div class="stat-value">{{ $stats['other'] }}</div>
                <div class="stat-label">Other Recurring</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="stat-value">{{ $stats['active'] }}</div>
                <div class="stat-label">Active</div>
            </div>
        </div>
    </div>

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$deductionTypes">
        <table class="hris-table" id="deductions-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Provider</th>
                    <th>Description</th>
                    <th>Computation Type</th>
                    <th>Employees</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deductionTypes as $d)
                    <tr id="deduction-row-{{ $d->id }}"
                        data-id="{{ $d->id }}"
                        data-type="{{ $d->type }}"
                        data-deduction-category="{{ $d->deduction_category ?? '' }}"
                        data-deduction-type="{{ $d->deduction_type ?? '' }}"
                        data-provider="{{ $d->provider ?? '' }}"
                        data-mandatory-key="{{ $d->mandatory_key ?? '' }}"
                        data-description="{{ $d->description ?? '' }}"
                        data-formula="{{ $d->formula ?? '' }}">
                        <td>
                            <i class="fas {{ $d->mandatory_key ? 'fa-landmark' : 'fa-receipt' }}"
                               style="color:{{ $d->mandatory_key ? '#5b21b6' : '#075985' }};margin-right:8px"></i>
                            <strong>{{ $d->type }}</strong>
                        </td>
                        <td>
                            @if($d->mandatory_key)
                                <span class="status-chip" style="background:#ede9fe;color:#5b21b6">Mandatory</span>
                            @else
                                <span class="status-chip" style="background:#e0f2fe;color:#075985">Other</span>
                            @endif
                        </td>
                        <td>
                            @if($d->is_active)
                                <span class="status-chip" style="background:#dcfce7;color:#166534">Active</span>
                            @else
                                <span class="status-chip" style="background:#fee2e2;color:#991b1b">Inactive</span>
                            @endif
                        </td>
                        <td>{{ $d->provider ?? '-' }}</td>
                        <td>{{ $d->description ?? '-' }}</td>
                        <td>{{ $d->computation_type ? ucfirst($d->computation_type) : '-' }}</td>
                        <td>
                            @if($d->deduction_category === 'other' && $d->computation_type)
                                <span class="status-chip" style="background:#ede9fe;color:#5b21b6" title="Auto-computed for every eligible employee type — no per-employee assignment needed">Auto (by type)</span>
                            @else
                                <span class="item-badge">{{ $d->employee_deductions_count }}</span>
                            @endif
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="{{ route('payroll.contributions.show', $d->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">View</a>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditDeduction({{ $d->id }})">Edit</button>
                                <form method="POST" action="{{ route('payroll.contributions.toggle-active', $d->id) }}"
                                      class="toggle-active-form" data-action="{{ $d->is_active ? 'deactivate' : 'activate' }}"
                                      data-mandatory="{{ $d->mandatory_key ? '1' : '0' }}"
                                      data-name="{{ $d->type }}" style="display:inline">
                                    @csrf @method('PUT')
                                    <button type="button" class="hris-btn {{ $d->is_active ? 'hris-btn-secondary' : 'hris-btn-primary' }} hris-btn-sm" onclick="confirmToggleActive(this)">
                                        {{ $d->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                @unless($d->mandatory_key)
                                    <form method="POST" action="{{ route('payroll.contributions.destroy', $d->id) }}" style="display:inline" id="delete-deduction-{{ $d->id }}">
                                        @csrf @method('DELETE')
                                        <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteDeduction({{ $d->id }})">Del</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">No deduction types defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
<dialog id="createDeductionModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-receipt"></i></span>
            <div>
                <h3>Add Deduction Type</h3>
                <p class="modal-subtitle">Define a new "Other Recurring Deduction" type</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.contributions.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        {{-- Mandatory rows are system-seeded and Loan providers are managed
             from the Loans page, so anything created here is always "other". --}}
        <input type="hidden" name="deduction_category" value="other">
        <div class="form-row">
            <div class="form-group">
                <label for="c-type"><i class="fas fa-tag"></i> Type / Name</label>
                <input type="text" name="type" id="c-type" value="{{ old('type') }}" class="form-input" required placeholder="e.g. Cellphone Allowance">
            </div>
            <div class="form-group">
                <label for="c-provider"><i class="fas fa-building"></i> Provider / Bank <small>(optional)</small></label>
                <input type="text" name="provider" id="c-provider" value="{{ old('provider') }}" class="form-input" placeholder="e.g. vendor or provider name">
            </div>
        </div>
        <div class="form-group">
            <label for="c-desc"><i class="fas fa-align-left"></i> Description <small>(optional)</small></label>
            <textarea name="description" id="c-desc" class="form-input" rows="3" placeholder="What this deduction is for">{{ old('description') }}</textarea>
        </div>
        <div class="form-group">
            <label for="c-formula"><i class="fas fa-note-sticky"></i> Formula / Notes <small>(optional, reference only)</small></label>
            <input type="text" name="formula" id="c-formula" value="{{ old('formula') }}" class="form-input" placeholder="Free-text note — not used in computation">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-plus"></i> Save</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="editDeductionModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-pen"></i></span>
            <div>
                <h3>Edit Deduction Type</h3>
                <p class="modal-subtitle" id="edit-deduction-subtitle">Update deduction</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" id="editDeductionForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-row">
            <div class="form-group">
                <label for="e-type"><i class="fas fa-tag"></i> Type / Name</label>
                <input type="text" name="type" id="e-type" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="e-provider"><i class="fas fa-building"></i> Provider / Bank</label>
                <input type="text" name="provider" id="e-provider" class="form-input" placeholder="Optional — e.g. vendor or provider name">
            </div>
        </div>
        <div class="form-group">
            <label for="e-category"><i class="fas fa-layer-group"></i> Category</label>
            <select name="deduction_category" id="e-category" class="form-input">
                <option value="">- Select category -</option>
                <option value="mandatory" id="e-category-mandatory-option" hidden>Mandatory Government Deduction (system)</option>
                <option value="other">Other Recurring Deduction</option>
            </select>
            <small id="e-category-locked-note" style="display:none;color:#64748b">This is a system mandatory deduction — its category can't be changed. Edit its rate from the deduction's own page instead.</small>
        </div>
        <div class="form-group">
            <label for="e-desc"><i class="fas fa-align-left"></i> Description</label>
            <textarea name="description" id="e-desc" class="form-input" rows="3"></textarea>
        </div>
        <div class="form-group">
            <label for="e-formula"><i class="fas fa-note-sticky"></i> Formula / Notes</label>
            <input type="text" name="formula" id="e-formula" class="form-input">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Update</button>
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
    document.getElementById('editDeductionForm').action = '{{ url("payroll-manager/contributions") }}/' + id;
    document.getElementById('e-type').value = row.dataset.type;
    document.getElementById('e-category').value = row.dataset.deductionCategory;
    document.getElementById('e-provider').value = row.dataset.provider;
    document.getElementById('e-desc').value = row.dataset.description;
    document.getElementById('e-formula').value = row.dataset.formula;
    document.getElementById('edit-deduction-subtitle').textContent = row.dataset.type;

    var isMandatory = !!row.dataset.mandatoryKey;
    document.getElementById('e-category').disabled = isMandatory;
    document.getElementById('e-category-mandatory-option').hidden = !isMandatory;
    document.getElementById('e-category-locked-note').style.display = isMandatory ? '' : 'none';

    document.getElementById('editDeductionModal').showModal();
}
function confirmDeleteDeduction(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-deduction-' + id).submit(); });
    } else if (confirm('Delete?')) { document.getElementById('delete-deduction-' + id).submit(); }
}
function confirmToggleActive(button) {
    var form = button.closest('form');
    var isActivate = form.dataset.action === 'activate';
    var isMandatory = form.dataset.mandatory === '1';
    var name = form.dataset.name || 'this deduction type';
    var run = function () { form.submit(); };

    if (typeof Swal !== 'undefined') {
        var html;
        if (isMandatory && !isActivate) {
            html = '<b>' + name + '</b> is a mandatory government deduction. Deactivating it will STOP withholding it from EVERY employee\'s pay starting the very next payroll run — this is not just hidden from new assignment, it stops for everyone immediately.';
        } else if (isMandatory) {
            html = '<b>' + name + '</b> will resume being withheld from every employee\'s pay on the next payroll run.';
        } else if (isActivate) {
            html = '<b>' + name + '</b> will be assignable to new employees again.';
        } else {
            html = '<b>' + name + '</b> will be hidden from new assignment. Employees already on it keep being deducted as normal.';
        }

        Swal.fire({
            icon: (isMandatory && !isActivate) ? 'error' : (isActivate ? 'question' : 'warning'),
            title: isActivate ? 'Activate this type?' : 'Deactivate this type?',
            html: html,
            showCancelButton: true,
            confirmButtonText: isActivate ? 'Yes, activate' : 'Yes, deactivate',
            confirmButtonColor: (isMandatory && !isActivate) ? '#dc2626' : undefined,
        }).then(function (r) { if (r.isConfirmed) run(); });
    } else if (confirm(isActivate ? 'Activate this type?' : 'Deactivate this type?')) {
        run();
    }
}
@if ($errors->any()) document.getElementById('createDeductionModal').showModal(); @endif
</script>
@endsection
