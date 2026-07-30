@extends('dashboards.layout', [
    'title' => 'Payroll Settings',
    'subtitle' => 'Manage payroll configuration, contribution tables, and signatories.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('createSettingModal').showModal()"><i class="fas fa-plus"></i> Add Setting</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <table class="hris-table" id="settings-table">
        <thead>
            <tr>
                <th>Key</th>
                <th>Value</th>
                <th>Updated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($settings as $setting)
                <tr id="setting-row-{{ $setting->id }}"
                    data-id="{{ $setting->id }}"
                    data-key="{{ $setting->key }}"
                    data-value="{{ $setting->value ?? '' }}"
                    data-updated="{{ $setting->updated_at->format('M d, Y H:i') }}">
                    <td><code>{{ $setting->key }}</code></td>
                    <td>{{ Str::limit($setting->value, 100) }}</td>
                    <td>{{ $setting->updated_at->format('M d, Y') }}</td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="btn btn-sm btn-outline" onclick="openShowSetting({{ $setting->id }})">View</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="openEditSetting({{ $setting->id }})">Edit</button>
                            <form method="POST" action="{{ route('payroll.settings.destroy', $setting->id) }}" style="display:inline" id="delete-setting-{{ $setting->id }}">
                                @csrf @method('DELETE')
                                <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeleteSetting({{ $setting->id }})">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty-state">No settings configured.</td></tr>
            @endforelse
        </tbody>
    </table>

    <section class="payroll-section" style="margin-top:24px;">
        <h2>Recommended Settings</h2>
        <div class="grid">
            <article class="tile">
                <strong>salary_matrix_version</strong>
                <small>Current salary standardization version/year.</small>
            </article>
            <article class="tile">
                <strong>contribution_tables</strong>
                <small>GSIS, PhilHealth, Pag-IBIG table version references.</small>
            </article>
            <article class="tile">
                <strong>signatories</strong>
                <small>Payroll signatory names and designations.</small>
            </article>
            <article class="tile">
                <strong>payroll_rules</strong>
                <small>Business rules for computation (e.g., prorate, rounding).</small>
            </article>
        </div>
    </section>
@endsection

@section('modals')
<dialog id="createSettingModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-sliders"></i></span>
            <div>
                <h3>Add Setting</h3>
                <p class="modal-subtitle">Create a new payroll configuration entry</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" action="{{ route('payroll.settings.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="c-key"><i class="fas fa-key"></i> Key</label>
            <input type="text" name="key" id="c-key" value="{{ old('key') }}" class="form-input" required placeholder="e.g. salary_matrix_version">
        </div>
        <div class="form-group">
            <label for="c-value"><i class="fas fa-align-left"></i> Value</label>
            <textarea name="value" id="c-value" class="form-input" rows="4">{{ old('value') }}</textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-plus"></i> Save</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="editSettingModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-pen"></i></span>
            <div>
                <h3>Edit Setting</h3>
                <p class="modal-subtitle" id="edit-setting-subtitle">Update setting</p>
            </div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <form method="POST" id="editSettingForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-group">
            <label><i class="fas fa-key"></i> Key</label>
            <input type="text" id="e-key-display" class="form-input" disabled>
        </div>
        <div class="form-group">
            <label for="e-value"><i class="fas fa-align-left"></i> Value</label>
            <textarea name="value" id="e-value" class="form-input" rows="4"></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

<dialog id="showSettingModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-circle-info"></i></span>
            <div><h3>Setting Details</h3></div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <div id="showSettingBody" style="margin-top:12px"></div>
    <form method="dialog" class="form-actions" style="margin-top:12px;text-align:right">
        <button class="btn btn-outline" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openEditSetting(id) {
    var row = document.getElementById('setting-row-' + id);
    if (!row) return;
    document.getElementById('editSettingForm').action = '{{ url("payroll-manager/settings") }}/' + id;
    document.getElementById('e-key-display').value = row.dataset.key;
    document.getElementById('e-value').value = row.dataset.value;
    document.getElementById('edit-setting-subtitle').textContent = row.dataset.key;
    document.getElementById('editSettingModal').showModal();
}
function openShowSetting(id) {
    var row = document.getElementById('setting-row-' + id);
    if (!row) return;
    document.getElementById('showSettingBody').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Key</strong></td><td style="padding:8px;border:1px solid #f1f5f9"><code>' + row.dataset.key + '</code></td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Value</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + (row.dataset.value || '-') + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Last Updated</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.updated + '</td></tr>' +
        '</tbody></table>';
    document.getElementById('showSettingModal').showModal();
}
function confirmDeleteSetting(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-setting-' + id).submit(); });
    } else if (confirm('Delete?')) { document.getElementById('delete-setting-' + id).submit(); }
}
@if ($errors->any()) document.getElementById('createSettingModal').showModal(); @endif
</script>
@endsection
