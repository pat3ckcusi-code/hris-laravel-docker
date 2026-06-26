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
    <dialog id="assign-modal" class="employee-modal">
        <form method="POST" action="{{ route('payroll.earnings.assignments.store', $earning->id) }}" class="payroll-form">
            @csrf
            <div class="modal-top-actions">
                <h3>Assign Employee</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-modal').close()">✕</button>
            </div>

            <div class="form-group">
                <label for="employee_id">Employee</label>
                <select name="employee_id" id="employee_id" class="form-input" required>
                    <option value="">- Select employee -</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                    @endforeach
                </select>
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
