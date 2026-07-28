@extends('dashboards.layout', [
    'title' => 'Salary Matrix',
    'subtitle' => $activeOrdinance
        ? "Effective {$selected} -{$activeOrdinance}"
        : "Salary Standardization Table, effective {$selected}",
])

@section('top_actions')
    <div class="plantilla-actions-group">
        <form method="GET" action="{{ route('payroll.salary-matrix.index') }}" style="display:inline-flex">
            <select name="version" class="hris-filter-select" onchange="this.form.submit()">
                @forelse($versions as $v)
                    <option value="{{ $v->effective_date->toDateString() }}" @selected($selected === $v->effective_date->toDateString())>
                        {{ $v->effective_date->format('M d, Y') }}{{ $v->ordinance_reference ? ' -'.\Illuminate\Support\Str::limit($v->ordinance_reference, 40) : '' }}
                    </option>
                @empty
                    <option value="">No versions yet</option>
                @endforelse
            </select>
        </form>
        <button type="button" class="plantilla-quiet-link" onclick="document.getElementById('createMatrixModal').showModal()"><i class="fas fa-pen"></i> Edit One Rate</button>
        @if($selected)
            <button type="button" class="plantilla-quiet-link" onclick="document.getElementById('editTrancheModal').showModal()"><i class="fas fa-calendar-pen"></i> Edit Tranche</button>
        @endif
        <a href="{{ route('payroll.plantilla.index') }}" class="plantilla-quiet-link"><i class="fas fa-arrow-left"></i> Back to Plantilla</a>
        <button type="button" class="btn btn-sm btn-add-position" onclick="openNewTranche()"><i class="fas fa-file-circle-plus"></i> New Rate Tranche</button>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <p class="text-muted" style="margin-bottom:16px;font-size:0.85rem">
        <i class="fas fa-circle-info" style="margin-right:6px"></i>
        Payroll always uses the rate version whose effective date is on or before the pay period being run -so publishing
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
                <input type="text" inputmode="decimal" name="amount" id="c-amount" value="{{ old('amount') }}" class="form-input currency-input" required>
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
            <input type="text" inputmode="decimal" name="amount" id="e-amount" class="form-input currency-input" required>
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

    <form method="POST" action="{{ route('payroll.salary-matrix.versions.store') }}" class="payroll-form" style="margin-top:12px;max-width:100%" onsubmit="return confirmPublishTranche(event)">
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
            <i class="fas fa-circle-info" style="margin-right:6px"></i>
            Leave a cell blank to skip that grade/step (it won't be created or changed). Values are pre-filled from
            {{ $selected ?? 'the current table' }} so you only need to edit what the new tranche changes.
        </p>
        <div class="plantilla-panel" style="max-height:min(58vh, 560px);overflow-y:auto;overflow-x:auto">
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
                                    <input type="text" inputmode="decimal" name="amounts[{{ $sg }}][{{ $s }}]"
                                        value="{{ $existing?->amount }}" class="form-input currency-input" style="min-width:90px;padding:5px 8px">
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

{{-- Edit Tranche (move the currently-selected version's date/reference) --}}
@if($selected)
<dialog id="editTrancheModal" class="employee-modal" style="min-height:440px">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Tranche</h3>
            <span class="record-email">Move every entry in the {{ $selected }} tranche to a different date</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.salary-matrix.versions.update') }}" class="payroll-form" style="margin-top:12px" onsubmit="return confirmMoveTranche(event)">
        @csrf @method('PUT')
        <input type="hidden" name="current_effective_date" value="{{ $selected }}">
        <div class="form-group">
            <label for="et-effective"><i class="fas fa-calendar-check"></i> Effective Date</label>
            <input type="date" name="effective_date" id="et-effective" value="{{ old('effective_date', $selected) }}" class="form-input" required>
        </div>
        <div class="form-group">
            <label for="et-ordinance"><i class="fas fa-file-signature"></i> Ordinance / Memo Reference <small>(optional)</small></label>
            <input type="text" name="ordinance_reference" id="et-ordinance" value="{{ old('ordinance_reference', $activeOrdinance) }}" class="form-input" placeholder="e.g. EO No. 64, s. 2024">
        </div>
        @error('current_effective_date')
            <div class="notice error">{{ $message }}</div>
        @enderror
        <p class="empty-state" style="text-align:left;padding:0;font-size:0.82rem">
            Moves every entry in this tranche to the new date. Already-locked payroll runs are unaffected; an unlocked
            run computed with the old date will use the new date the next time it's recomputed.
        </p>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-calendar-pen"></i> Move Tranche</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endif
@endsection

@section('page_scripts_after')
<script>
// Peso-value inputs (.currency-input) display comma-grouped, 2-decimal
// values (e.g. "438,844.00") while not focused, and their raw editable
// number while focused - <input type="number"> can't contain commas at
// all, so these are plain text inputs with the formatting handled here.
// Delegated on document so it also covers the 264 grid cells in the New
// Rate Tranche modal without attaching a listener to each one.
function formatCurrencyInput(input) {
    var raw = String(input.value).replace(/,/g, '').trim();
    if (raw === '' || isNaN(raw)) { return; }
    input.value = parseFloat(raw).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function unformatCurrencyInput(input) {
    input.value = String(input.value).replace(/,/g, '');
}
document.querySelectorAll('.currency-input').forEach(function (input) {
    if (input.value !== '') { formatCurrencyInput(input); }
});
document.addEventListener('focusin', function (e) {
    if (e.target.classList && e.target.classList.contains('currency-input')) { unformatCurrencyInput(e.target); }
});
document.addEventListener('focusout', function (e) {
    if (e.target.classList && e.target.classList.contains('currency-input')) { formatCurrencyInput(e.target); }
});
// Capture phase so commas are stripped before any other submit handler
// (e.g. confirmPublishTranche/confirmMoveTranche) reads field values or
// the browser serializes the form.
document.addEventListener('submit', function (e) {
    if (!e.target.querySelectorAll) { return; }
    e.target.querySelectorAll('.currency-input').forEach(unformatCurrencyInput);
}, true);

function openEditMatrix(id, sg, step, effectiveDate, amount) {
    document.getElementById('editMatrixForm').action = '{{ url("payroll-manager/salary-matrix") }}/' + id;
    var amountInput = document.getElementById('e-amount');
    amountInput.value = amount;
    formatCurrencyInput(amountInput);
    document.getElementById('edit-matrix-subtitle').textContent = 'SG-' + sg + ' Step ' + step + ' (effective ' + effectiveDate + ')';
    document.getElementById('editMatrixModal').showModal();
}

function openNewTranche() {
    document.getElementById('newTrancheModal').showModal();
}

function confirmPublishTranche(event) {
    event.preventDefault();
    var form = event.target;
    var effectiveDate = document.getElementById('nt-effective').value;
    var ordinance = document.getElementById('nt-ordinance').value;
    var filledCells = Array.from(form.querySelectorAll('input[name^="amounts"]')).filter(function (input) {
        return input.value.trim() !== '';
    }).length;
    var message = 'Publish a new rate tranche effective ' + effectiveDate + ' (' + filledCells + ' entr' + (filledCells === 1 ? 'y' : 'ies') + ')'
        + (ordinance ? ', referencing "' + ordinance + '"' : '') + '? This takes effect for any payroll period on or after that date.';

    if (typeof Swal !== 'undefined') {
        // Native <dialog> renders in the browser's top layer, which sits above
        // anything appended to document.body (Swal's default target) - point
        // Swal at the open dialog itself so its popup stacks inside it instead
        // of behind it.
        Swal.fire({
            title: 'Publish this tranche?', text: message, icon: 'question',
            showCancelButton: true, confirmButtonText: 'Yes, publish',
            target: document.getElementById('newTrancheModal'),
        }).then(function (r) {
            if (r.isConfirmed) {
                form.submit();
            } else {
                // The capture-phase submit listener already stripped commas
                // from every .currency-input cell before this handler ran -
                // restore the comma display since the publish was cancelled.
                form.querySelectorAll('.currency-input').forEach(formatCurrencyInput);
            }
        });
    } else if (confirm(message)) {
        form.submit();
    }
    return false;
}

function confirmMoveTranche(event) {
    event.preventDefault();
    var form = event.target;
    var fromDate = document.querySelector('input[name="current_effective_date"]').value;
    var toDate = document.getElementById('et-effective').value;
    var ordinance = document.getElementById('et-ordinance').value;
    var message = fromDate === toDate
        ? 'Update this tranche\'s ordinance reference to "' + ordinance + '"?'
        : 'Move every entry in the ' + fromDate + ' tranche to ' + toDate + '? Already-locked payroll runs are unaffected, but an unlocked run computed with the old date will use the new date the next time it\'s recomputed.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: fromDate === toDate ? 'Update ordinance reference?' : 'Move this tranche?',
            text: message, icon: 'question',
            showCancelButton: true, confirmButtonText: fromDate === toDate ? 'Yes, update' : 'Yes, move',
            target: document.getElementById('editTrancheModal'),
        }).then(function (r) { if (r.isConfirmed) form.submit(); });
    } else if (confirm(message)) {
        form.submit();
    }
    return false;
}

@if ($errors->any())
    document.getElementById('createMatrixModal').showModal();
@endif
@if (session('error'))
    document.getElementById('editTrancheModal')?.showModal();
@endif
</script>
@endsection
