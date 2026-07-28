@extends('dashboards.layout', [
    'title' => $earning->type,
    'subtitle' => 'Earning type details and employee assignments.',
])

@section('top_actions')
    <div class="header-actions">
        <button type="button" class="btn btn-sm" onclick="document.getElementById('assign-modal').showModal()">
            <i class="fas fa-plus"></i> Assign Employee
        </button>
        <a href="{{ route('payroll.earnings.index') }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <div class="detail-card">
        <div class="detail-row"><strong>Type:</strong> {{ $earning->type }}</div>
        <div class="detail-row"><strong>Description:</strong> {{ $earning->description ?? '-' }}</div>
        <div class="detail-row"><strong>Recurring:</strong> {{ $earning->recurring ? 'Yes' : 'No' }}</div>
    </div>

    <section class="payroll-section">
        <h2>Assigned Employees</h2>
        @if($earning->employeeEarnings->count())
            <div class="hris-table-wrapper">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Value</th>
                        <th>Recurring</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($earning->employeeEarnings as $ee)
                        <tr>
                            <td>{{ $ee->employee->name ?? '-' }}</td>
                            <td>
                                @if($ee->amount_type === 'percentage')
                                    {{ $ee->percentage }}% of basic
                                @else
                                    ₱{{ number_format($ee->amount, 2) }}
                                @endif
                            </td>
                            <td>{{ $ee->recurring ? 'Yes' : 'No' }}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline"
                                    onclick="openEditAssignment(
                                        {{ $ee->id }},
                                        '{{ $ee->amount_type ?? 'fixed' }}',
                                        '{{ $ee->amount }}',
                                        '{{ $ee->percentage ?? '' }}',
                                        {{ $ee->recurring ? 'true' : 'false' }}
                                    )">Edit</button>
                                <button type="button" class="btn btn-sm btn-danger"
                                    onclick="confirmDeleteAssignment({{ $earning->id }}, {{ $ee->id }})">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="empty-state">No employees assigned yet.
                <button type="button" class="btn btn-sm" onclick="document.getElementById('assign-modal').showModal()">
                    Assign Employee
                </button>
            </p>
        @endif
    </section>

    {{-- Assign modal --}}
    <dialog id="assign-modal" class="employee-modal modal-lg">
        <form method="POST" action="{{ route('payroll.earnings.assignments.store', $earning->id) }}" class="payroll-form">
            @csrf
            <div class="modal-top-actions">
                <h3>Assign Employee(s)</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-modal').close()">✕</button>
            </div>

            @error('employee_ids')
                <div class="notice error">{{ $message }}</div>
            @enderror

            <div class="form-group">
                <label>Employees</label>
                <div class="form-row" style="gap:12px;align-items:center;flex-wrap:wrap;">
                    <select id="assign-emp-type-filter" class="form-input" style="max-width:220px" onchange="filterAssignEmployees()">
                        <option value="">All types</option>
                        @foreach($employeeTypes as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                        <option value="Unspecified">Unspecified</option>
                    </select>
                    <input type="text" id="assign-emp-search" class="form-input" style="max-width:240px" placeholder="Search name or EmpNo…" oninput="filterAssignEmployees()">
                    <label class="checkbox-label">
                        <input type="checkbox" id="assign-select-all" onchange="toggleAssignSelectAll(this.checked)"> Select all visible
                    </label>
                    <span id="assign-selected-count" style="font-size:.8rem;color:#64748b">0 selected</span>
                </div>

                <div id="assign-emp-list" style="max-height:280px;overflow:auto;border:1px solid #e2e8f0;border-radius:6px;margin-top:8px;padding:6px 10px">
                    @forelse($employees as $emp)
                        @php $alreadyAssigned = in_array($emp->id, $assignedEmployeeIds); @endphp
                        <div class="assign-emp-row"
                             data-name="{{ strtolower($emp->name.' '.$emp->EmpNo) }}"
                             data-type="{{ $emp->employee_type ?: 'Unspecified' }}"
                             style="padding:4px 0">
                            <label class="checkbox-label">
                                <input type="checkbox" name="employee_ids[]" value="{{ $emp->id }}"
                                       class="assign-emp-checkbox" onchange="updateAssignSelectedState()"
                                       {{ $alreadyAssigned ? 'disabled' : '' }}>
                                {{ $emp->name }}
                                @if($emp->EmpNo)<span style="color:#94a3b8">({{ $emp->EmpNo }})</span>@endif
                                @if($alreadyAssigned)<span class="status-chip" style="margin-left:6px">Already assigned</span>@endif
                            </label>
                        </div>
                    @empty
                        <p class="empty-state">No employees found.</p>
                    @endforelse
                </div>
                <p id="assign-emp-empty-state" class="empty-state" style="display:none">No employees match this filter.</p>
            </div>

            <div class="form-group">
                <label>Amount Type</label>
                <div class="form-row">
                    <label class="checkbox-label">
                        <input type="radio" name="amount_type" value="fixed" checked onchange="toggleAmountType('fixed', '')"> Fixed (₱)
                    </label>
                    <label class="checkbox-label">
                        <input type="radio" name="amount_type" value="percentage" onchange="toggleAmountType('percentage', '')"> % of Basic Salary
                    </label>
                </div>
            </div>

            <div class="form-group" id="amount-group">
                <label for="amount">Amount (₱)</label>
                <input type="number" name="amount" id="amount" class="form-input" min="0" step="0.01" placeholder="e.g. 2000.00">
            </div>

            <div class="form-group" id="percentage-group" style="display:none">
                <label for="percentage">Percentage (%)</label>
                <input type="number" name="percentage" id="percentage" class="form-input" min="0" max="100" step="0.01" placeholder="e.g. 25">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="recurring" value="1" checked> Recurring (monthly)
                </label>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Save</button>
            </div>
        </form>
    </dialog>

    {{-- Edit modal --}}
    <dialog id="edit-modal" class="employee-modal">
        <form method="POST" id="edit-form" class="payroll-form">
            @csrf
            @method('PUT')
            <div class="modal-top-actions">
                <h3>Edit Assignment</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-modal').close()">✕</button>
            </div>

            <div class="form-group">
                <label>Amount Type</label>
                <div class="form-row">
                    <label class="checkbox-label">
                        <input type="radio" name="amount_type" value="fixed" id="edit-type-fixed" onchange="toggleAmountType('fixed', 'edit-')"> Fixed (₱)
                    </label>
                    <label class="checkbox-label">
                        <input type="radio" name="amount_type" value="percentage" id="edit-type-pct" onchange="toggleAmountType('percentage', 'edit-')"> % of Basic Salary
                    </label>
                </div>
            </div>

            <div class="form-group" id="edit-amount-group">
                <label for="edit-amount">Amount (₱)</label>
                <input type="number" name="amount" id="edit-amount" class="form-input" min="0" step="0.01">
            </div>

            <div class="form-group" id="edit-percentage-group" style="display:none">
                <label for="edit-percentage">Percentage (%)</label>
                <input type="number" name="percentage" id="edit-percentage" class="form-input" min="0" max="100" step="0.01">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="recurring" id="edit-recurring" value="1"> Recurring (monthly)
                </label>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Update</button>
            </div>
        </form>
    </dialog>

    {{-- Hidden delete form --}}
    <form id="delete-form" method="POST" style="display:none">
        @csrf
        @method('DELETE')
    </form>
@endsection

@section('page_scripts_after')
<script>
function filterAssignEmployees() {
    const type = document.getElementById('assign-emp-type-filter').value.toLowerCase();
    const q = document.getElementById('assign-emp-search').value.toLowerCase().trim();
    let anyVisible = false;

    document.querySelectorAll('#assign-emp-list .assign-emp-row').forEach(row => {
        const matchesType = !type || (row.dataset.type || '').toLowerCase() === type;
        const matchesSearch = !q || (row.dataset.name || '').includes(q);
        const visible = matchesType && matchesSearch;
        row.style.display = visible ? '' : 'none';
        if (visible) anyVisible = true;
    });

    document.getElementById('assign-emp-empty-state').style.display = anyVisible ? 'none' : '';
    updateAssignSelectedState();
}

function toggleAssignSelectAll(checked) {
    document.querySelectorAll('#assign-emp-list .assign-emp-row').forEach(row => {
        if (row.style.display === 'none') return;
        const cb = row.querySelector('.assign-emp-checkbox');
        if (cb && !cb.disabled) cb.checked = checked;
    });
    updateAssignSelectedState();
}

function updateAssignSelectedState() {
    const visibleCheckboxes = Array.from(document.querySelectorAll('#assign-emp-list .assign-emp-row'))
        .filter(row => row.style.display !== 'none')
        .map(row => row.querySelector('.assign-emp-checkbox'))
        .filter(cb => cb && !cb.disabled);
    const checkedCount = visibleCheckboxes.filter(cb => cb.checked).length;

    document.getElementById('assign-selected-count').textContent = checkedCount + ' selected';

    const selectAllCb = document.getElementById('assign-select-all');
    if (selectAllCb) {
        selectAllCb.checked = visibleCheckboxes.length > 0 && checkedCount === visibleCheckboxes.length;
    }
}

@if ($errors->any())
    document.getElementById('assign-modal').showModal();
@endif

function toggleAmountType(type, prefix) {
    const amountGroup     = document.getElementById(prefix + 'amount-group');
    const percentageGroup = document.getElementById(prefix + 'percentage-group');
    if (type === 'percentage') {
        amountGroup.style.display     = 'none';
        percentageGroup.style.display = '';
    } else {
        amountGroup.style.display     = '';
        percentageGroup.style.display = 'none';
    }
}

function openEditAssignment(id, amountType, amount, percentage, recurring) {
    const earningId = {{ $earning->id }};
    document.getElementById('edit-form').action =
        '/payroll-manager/earnings/' + earningId + '/assignments/' + id;

    if (amountType === 'percentage') {
        document.getElementById('edit-type-pct').checked   = true;
        document.getElementById('edit-type-fixed').checked = false;
        toggleAmountType('percentage', 'edit-');
        document.getElementById('edit-percentage').value = percentage;
    } else {
        document.getElementById('edit-type-fixed').checked = true;
        document.getElementById('edit-type-pct').checked   = false;
        toggleAmountType('fixed', 'edit-');
        document.getElementById('edit-amount').value = amount;
    }

    document.getElementById('edit-recurring').checked = recurring;
    document.getElementById('edit-modal').showModal();
}

function confirmDeleteAssignment(earningId, assignmentId) {
    const url = '/payroll-manager/earnings/' + earningId + '/assignments/' + assignmentId;
    const run = () => {
        const form = document.getElementById('delete-form');
        form.action = url;
        form.submit();
    };
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Remove assignment?',
            text: 'This will stop including this allowance in future payroll runs.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Remove',
        }).then(r => { if (r.isConfirmed) run(); });
    } else if (confirm('Remove this assignment?')) {
        run();
    }
}
</script>
@endsection
