@extends('dashboards.layout', [
    'title' => 'Salary Matrix',
    'subtitle' => 'Salary Standardization Table by grade, step, and year.',
])

@section('top_actions')
    <div class="header-actions">
        <form method="GET" action="{{ route('payroll.salary-matrix.index') }}" class="filter-form" style="display:inline-flex;gap:8px;">
            <select name="year" class="form-input">
                @foreach($years as $y)
                    <option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>
                @endforeach
                @if($years->isEmpty())
                    <option value="{{ date('Y') }}">{{ date('Y') }}</option>
                @endif
            </select>
            <button type="submit" class="btn btn-sm">Filter</button>
        </form>
        <button type="button" class="btn btn-sm" onclick="document.getElementById('createMatrixModal').showModal()"><i class="fas fa-plus"></i> Add Entry</button>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    @if($matrix->count())
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
                        <td><strong>{{ $sg }}</strong></td>
                        @for($s = 1; $s <= 8; $s++)
                            @php $entry = $steps->firstWhere('step', $s); @endphp
                            <td>
                                @if($entry)
                                    <span class="editable-cell" onclick="openEditMatrix({{ $entry->id }}, {{ $entry->sg }}, {{ $entry->step }}, {{ $entry->year }}, {{ $entry->amount }})" style="cursor:pointer" title="Click to edit">
                                        ₱{{ number_format($entry->amount, 2) }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="empty-state">No salary matrix entries for {{ $year }}.</p>
    @endif
@endsection

@section('modals')
{{-- Create Matrix Entry Modal --}}
<dialog id="createMatrixModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Add Salary Matrix Entry</h3>
            <span class="record-email">Define salary amount for a grade/step/year</span>
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
            <div class="form-group">
                <label for="c-year">Year</label>
                <input type="number" name="year" id="c-year" min="2000" max="2099" value="{{ old('year', date('Y')) }}" class="form-input" required>
            </div>
        </div>
        <div class="form-group">
            <label for="c-amount">Amount (₱)</label>
            <input type="number" step="0.01" name="amount" id="c-amount" min="0" value="{{ old('amount') }}" class="form-input" required>
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
@endsection

@section('page_scripts_after')
<script>
function openEditMatrix(id, sg, step, year, amount) {
    document.getElementById('editMatrixForm').action = '{{ url("payroll-manager/salary-matrix") }}/' + id;
    document.getElementById('e-amount').value = amount;
    document.getElementById('edit-matrix-subtitle').textContent = 'SG-' + sg + ' Step ' + step + ' (' + year + ')';
    document.getElementById('editMatrixModal').showModal();
}

@if ($errors->any())
    document.getElementById('createMatrixModal').showModal();
@endif
</script>
@endsection
