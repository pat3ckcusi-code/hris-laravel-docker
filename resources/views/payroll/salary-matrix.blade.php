@extends('dashboards.layout', [
    'title' => 'Salary Matrix',
    'subtitle' => $activeOrdinance
        ? "Effective {$selected} — {$activeOrdinance}"
        : "Salary Standardization Table, effective {$selected}",
])

@section('top_actions')
    <form method="GET" action="{{ route('payroll.salary-matrix.index') }}" style="display:inline-flex">
        <select name="version" class="hris-filter-select" onchange="this.form.submit()">
            @forelse($versions as $v)
                <option value="{{ $v->effective_date->toDateString() }}" @selected($selected === $v->effective_date->toDateString())>
                    {{ $v->effective_date->format('M d, Y') }}{{ $v->ordinance_reference ? ' — '.\Illuminate\Support\Str::limit($v->ordinance_reference, 40) : '' }}
                </option>
            @empty
                <option value="">No versions yet</option>
            @endforelse
        </select>
    </form>
    <a href="{{ route('payroll.plantilla.index') }}" class="btn btn-sm btn-outline">Back to Plantilla</a>
    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('createMatrixModal').showModal()"><i class="fas fa-pen"></i> Edit One Rate</button>
    <button type="button" class="btn btn-sm" onclick="openNewTranche()"><i class="fas fa-file-circle-plus"></i> New Rate Tranche</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <p class="text-muted" style="margin-bottom:16px;font-size:0.85rem">
        <i class="fas fa-circle-info" style="margin-right:6px"></i>
        Payroll always uses the rate version whose effective date is on or before the pay period being run — so publishing
        a new tranche here (even mid-year) takes effect automatically without touching plantilla items or payroll code.
    </p>

    @if($matrix->count())
        <div class="plantilla-panel overflow-x-auto">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>SG</th>
                        @for($s = 1; $s <= 8; $s++)
                            <th>Step {{ $s }}</th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @foreach($matrix as $sg => $steps)
                        <tr>
                            <td><span class="sg-badge">SG {{ $sg }}</span></td>
                            @for($s = 1; $s <= 8; $s++)
                                @php $entry = $steps->firstWhere('step', $s); @endphp
                                <td>
                                    @if($entry)
                                        <span class="editable-cell" onclick="openEditMatrix({{ $entry->id }}, {{ $entry->sg }}, {{ $entry->step }}, '{{ $entry->effective_date->toDateString() }}', {{ $entry->amount }})" style="cursor:pointer" title="Click to edit">
                                            ₱{{ number_format($entry->amount, 2) }}
                                        </span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="empty-state"><i class="fas fa-table" style="display:block;font-size:1.5rem;margin-bottom:8px;color:#cbd5e1"></i>No salary matrix published yet. Click <strong>New Rate Tranche</strong> to publish the first one.</p>
    @endif
@endsection

@section('modals')
{{-- Edit single rate --}}
<dialog id="createMatrixModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit One Rate</h3>
            <span class="record-email">Correct or add a single grade/step amount</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.salary-matrix.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-row">
            <div class="form-group">
                <label for="c-sg">Salary Grade</label>
                <input type="number" name="sg" id="c-sg" min="1" max="33" value="{{ old('sg') }}" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="c-step">Step</label>
                <input type="number" name="step" id="c-step" min="1" max="8" value="{{ old('step') }}" class="form-input" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="c-effective">Effective Date</label>
                <input type="date" name="effective_date" id="c-effective" value="{{ old('effective_date', $selected) }}" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="c-amount">Amount (₱)</label>
                <input type="number" step="0.01" name="amount" id="c-amount" min="0" value="{{ old('amount') }}" class="form-input" required>
            </div>
        </div>
        <div class="form-group">
            <label for="c-ordinance">Ordinance / Memo Reference <small>(optional)</small></label>
            <input type="text" name="ordinance_reference" id="c-ordinance" value="{{ old('ordinance_reference') }}" class="form-input" placeholder="e.g. EO No. 64, s. 2024">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Edit Matrix Entry Modal --}}
<dialog id="editMatrixModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Salary Matrix Entry</h3>
            <span class="record-email" id="edit-matrix-subtitle">Update amount</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" id="editMatrixForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-group">
            <label for="e-amount">Amount (₱)</label>
            <input type="number" step="0.01" name="amount" id="e-amount" min="0" class="form-input" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- New Rate Tranche (bulk) --}}
<dialog id="newTrancheModal" class="employee-modal modal-lg">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Publish New Rate Tranche</h3>
            <span class="record-email">Enter the effective date and any changed amounts - pre-filled from the current table</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.salary-matrix.versions.store') }}" class="payroll-form" style="margin-top:12px;max-width:100%">
        @csrf
        <div class="form-row">
            <div class="form-group">
                <label for="nt-effective"><i class="fas fa-calendar-check"></i> Effective Date</label>
                <input type="date" name="effective_date" id="nt-effective" value="{{ now()->addYearNoOverflow()->startOfYear()->toDateString() }}" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="nt-ordinance"><i class="fas fa-file-signature"></i> Ordinance / Memo Reference</label>
                <input type="text" name="ordinance_reference" id="nt-ordinance" class="form-input" placeholder="e.g. EO No. 64, s. 2024 (NBC No. 601)">
            </div>
        </div>
        <p class="text-muted" style="font-size:0.82rem;margin:4px 0 12px">
            Leave a cell blank to skip that grade/step (it won't be created or changed). Values are pre-filled from
            {{ $selected ?? 'the current table' }} so you only need to edit what the new tranche changes.
        </p>
        <div class="plantilla-panel overflow-x-auto" style="max-height:min(52vh, 480px);overflow-y:auto">
            <table class="hris-table" style="font-size:0.82rem">
                <thead>
                    <tr>
                        <th>SG</th>
                        @for($s = 1; $s <= 8; $s++)
                            <th>Step {{ $s }}</th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @for($sg = 1; $sg <= 33; $sg++)
                        <tr>
                            <td><span class="sg-badge">SG {{ $sg }}</span></td>
                            @for($s = 1; $s <= 8; $s++)
                                @php $existing = ($matrix[$sg] ?? collect())->firstWhere('step', $s); @endphp
                                <td>
                                    <input type="number" step="0.01" min="0" name="amounts[{{ $sg }}][{{ $s }}]"
                                        value="{{ $existing?->amount }}" class="form-input" style="min-width:90px;padding:5px 8px">
                                </td>
                            @endfor
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-file-circle-plus"></i> Publish Tranche</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openEditMatrix(id, sg, step, effectiveDate, amount) {
    document.getElementById('editMatrixForm').action = '{{ url("payroll-manager/salary-matrix") }}/' + id;
    document.getElementById('e-amount').value = amount;
    document.getElementById('edit-matrix-subtitle').textContent = 'SG-' + sg + ' Step ' + step + ' (effective ' + effectiveDate + ')';
    document.getElementById('editMatrixModal').showModal();
}

function openNewTranche() {
    document.getElementById('newTrancheModal').showModal();
}

@if ($errors->any())
    document.getElementById('createMatrixModal').showModal();
@endif
</script>
@endsection
