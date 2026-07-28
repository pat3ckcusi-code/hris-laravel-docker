@extends('dashboards.layout', [
    'title' => 'Loans',
    'subtitle' => 'All employee loans across every provider, in one place.',
])

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <div class="plantilla-stats">
        <div class="stat-tile stat-info">
            <div class="stat-icon"><i class="fas fa-building"></i></div>
            <div>
                <div class="stat-value">{{ $stats['providers'] }}</div>
                <div class="stat-label">Loan Providers</div>
            </div>
        </div>
        <div class="stat-tile">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="stat-value">{{ $stats['total_loans'] }}</div>
                <div class="stat-label">Total Loans</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="stat-value">{{ $stats['active_loans'] }}</div>
                <div class="stat-label">Active Loans</div>
            </div>
        </div>
        <div class="stat-tile stat-promo">
            <div class="stat-icon"><i class="fas fa-sack-dollar"></i></div>
            <div>
                <div class="stat-value">₱{{ number_format($stats['outstanding_balance'], 2) }}</div>
                <div class="stat-label">Outstanding Balance</div>
            </div>
        </div>
    </div>

    <section class="payroll-section" style="margin-bottom:20px">
        <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
            <h2 style="margin:0"><i class="fas fa-hand-holding-dollar"></i> Loan Providers</h2>
            <button type="button" class="btn btn-sm" onclick="document.getElementById('add-provider-modal').showModal()"><i class="fas fa-plus"></i> Add Provider</button>
        </div>
        @if($providers->count())
            <div class="hris-table-wrapper">
            <table class="hris-table" id="providers-table">
                <thead>
                    <tr>
                        <th>Type / Name</th>
                        <th>Provider / Bank</th>
                        <th>Status</th>
                        <th>Loans</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($providers as $p)
                        <tr id="provider-row-{{ $p->id }}"
                            data-type="{{ $p->type }}"
                            data-provider="{{ $p->provider }}"
                            data-description="{{ $p->description }}">
                            <td><i class="fas fa-hand-holding-dollar" style="color:#1e40af;margin-right:8px"></i><strong>{{ $p->type }}</strong></td>
                            <td>{{ $p->provider ?? '-' }}</td>
                            <td>
                                @if($p->is_active)
                                    <span class="status-chip" style="background:#dcfce7;color:#166534">Active</span>
                                @else
                                    <span class="status-chip" style="background:#fee2e2;color:#991b1b">Inactive</span>
                                @endif
                            </td>
                            <td><span class="item-badge">{{ $p->loans_count }}</span></td>
                            <td>
                                <div class="action-btns">
                                    <a href="{{ route('payroll.contributions.show', $p->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">View</a>
                                    <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditProvider({{ $p->id }})">Edit</button>
                                    <form method="POST" action="{{ route('payroll.contributions.toggle-active', $p->id) }}"
                                          class="provider-toggle-form" data-action="{{ $p->is_active ? 'deactivate' : 'activate' }}"
                                          data-name="{{ $p->type }}" style="display:inline">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="_redirect" value="{{ route('payroll.loans.index') }}">
                                        <button type="button" class="hris-btn {{ $p->is_active ? 'hris-btn-secondary' : 'hris-btn-primary' }} hris-btn-sm" onclick="confirmToggleProviderActive(this)">
                                            {{ $p->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('payroll.contributions.destroy', $p->id) }}" style="display:inline" id="delete-provider-{{ $p->id }}">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="_redirect" value="{{ route('payroll.loans.index') }}">
                                        <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteProvider({{ $p->id }})">Del</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="empty-state">No loan providers yet. Add one to start assigning loans.</p>
        @endif
    </section>

    <x-hris.table-layout title="Individual Loans" :showSearch="false" :showMonthFilter="false" :paginator="$loans">
        <x-slot:filters>
            <form method="GET" action="{{ route('payroll.loans.index') }}" class="hris-search-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search employee or provider…" class="hris-search-input" style="max-width:260px">
                <select name="provider" class="form-input" style="max-width:200px">
                    <option value="">All providers</option>
                    @foreach($providers as $p)
                        <option value="{{ $p->id }}" @selected($provider == $p->id)>{{ $p->type }}</option>
                    @endforeach
                </select>
                <select name="status" class="form-input" style="max-width:180px">
                    <option value="">All statuses</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="paid" @selected($status === 'paid')>Paid</option>
                    <option value="suspended" @selected($status === 'suspended')>Suspended</option>
                </select>
                <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm">Filter</button>
                @if($search !== '' || $status !== '' || $provider !== '')
                    <a href="{{ route('payroll.loans.index') }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        <table class="hris-table" id="loans-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Provider / Type</th>
                    <th>Balance</th>
                    <th>Monthly Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($loans as $loan)
                    <tr>
                        <td>{{ $loan->employee->name ?? '-' }}</td>
                        <td>
                            {{ $loan->deduction->type ?? '-' }}
                            @if($loan->deduction?->provider)
                                <span style="color:#94a3b8">({{ $loan->deduction->provider }})</span>
                            @endif
                        </td>
                        <td>₱{{ number_format($loan->balance, 2) }}</td>
                        <td>₱{{ number_format($loan->monthly_payment, 2) }}</td>
                        <td><span class="status-chip status-{{ $loan->status }}">{{ ucfirst($loan->status) }}</span></td>
                        <td>
                            <div class="action-btns">
                                @if($loan->deduction)
                                    <a href="{{ route('payroll.contributions.show', $loan->deduction_id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">View Type</a>
                                @endif
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm"
                                    onclick="openEditLoan({{ $loan->deduction_id }}, {{ $loan->id }}, '{{ $loan->balance }}', '{{ $loan->monthly_payment }}', '{{ $loan->status }}')">Edit</button>
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm"
                                    onclick='openLoanHistory("{{ $loan->employee->name ?? "-" }}", {{ $loan->billingHistory->map(fn ($h) => ["month" => $h->billing_month->format("F Y"), "balance" => number_format($h->balance, 2), "monthly_payment" => number_format($h->monthly_payment, 2)])->toJson() }})'>History</button>
                                <button type="button" class="hris-btn hris-btn-danger hris-btn-sm"
                                    onclick="confirmDeleteLoan({{ $loan->deduction_id }}, {{ $loan->id }})">Remove</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No loans match the current filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>

    {{-- Edit Loan modal --}}
    <dialog id="edit-loan-modal" class="employee-modal">
        <form method="POST" id="edit-loan-form" class="payroll-form">
            @csrf @method('PUT')
            <input type="hidden" name="_redirect" value="{{ route('payroll.loans.index', request()->query()) }}">
            <div class="modal-top-actions">
                <h3>Edit Loan</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-loan-modal').close()">✕</button>
            </div>
            <div class="form-group">
                <label for="edit-loan-balance">Balance (₱)</label>
                <input type="number" name="balance" id="edit-loan-balance" class="form-input" min="0" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="edit-loan-monthly">Monthly Payment (₱)</label>
                <input type="number" name="monthly_payment" id="edit-loan-monthly" class="form-input" min="0" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="edit-loan-status">Status</label>
                <select name="status" id="edit-loan-status" class="form-input" required>
                    <option value="active">Active</option>
                    <option value="paid">Paid</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-loan-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Update</button>
            </div>
        </form>
    </dialog>

    {{-- Hidden delete-loan form --}}
    <form id="delete-loan-form" method="POST" style="display:none">
        @csrf @method('DELETE')
        <input type="hidden" name="_redirect" value="{{ route('payroll.loans.index', request()->query()) }}">
    </form>

    {{-- Loan billing History modal (read-only, shared by every loan row) --}}
    <dialog id="loan-history-modal" class="employee-modal">
        <div class="modal-top-actions">
            <h3 id="loan-history-title">Billing History</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('loan-history-modal').close()">✕</button>
        </div>
        <div class="hris-table-wrapper">
            <table class="hris-table">
                <thead><tr><th>Month</th><th>Balance</th><th>Monthly Payment</th></tr></thead>
                <tbody id="loan-history-body"></tbody>
            </table>
        </div>
        <p id="loan-history-empty" class="empty-state" style="display:none">No billing history recorded yet.</p>
        <div class="form-actions">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('loan-history-modal').close()">Close</button>
        </div>
    </dialog>

    {{-- Add Loan Provider modal --}}
    <dialog id="add-provider-modal" class="employee-modal">
        <form method="POST" action="{{ route('payroll.contributions.store') }}" class="payroll-form">
            @csrf
            <input type="hidden" name="deduction_category" value="loan">
            <input type="hidden" name="_redirect" value="{{ route('payroll.loans.index') }}">
            <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
                <div>
                    <h3 style="margin:0"><i class="fas fa-hand-holding-dollar" style="color:#1e40af;margin-right:8px"></i>Add Loan Provider</h3>
                    <span class="record-email">Register a lender or agency you can assign employee loans to</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('add-provider-modal').close()">✕</button>
            </div>
            <div class="form-row" style="margin-top:12px">
                <div class="form-group">
                    <label for="ap-type"><i class="fas fa-tag"></i> Type / Name</label>
                    <input type="text" name="type" id="ap-type" class="form-input" required placeholder="e.g. CGCEMCO, LBP Salary Loan">
                </div>
                <div class="form-group">
                    <label for="ap-provider"><i class="fas fa-building"></i> Provider / Bank <small>(optional)</small></label>
                    <input type="text" name="provider" id="ap-provider" class="form-input" placeholder="e.g. CGCEMCO, LBP, DBP">
                </div>
            </div>
            <div class="form-group">
                <label for="ap-desc"><i class="fas fa-align-left"></i> Description <small>(optional)</small></label>
                <textarea name="description" id="ap-desc" class="form-input" rows="3" placeholder="Notes about this provider"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('add-provider-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm"><i class="fas fa-plus"></i> Save</button>
            </div>
        </form>
    </dialog>

    {{-- Edit Loan Provider modal --}}
    <dialog id="edit-provider-modal" class="employee-modal">
        <form method="POST" id="edit-provider-form" class="payroll-form">
            @csrf @method('PUT')
            <input type="hidden" name="deduction_category" value="loan">
            <input type="hidden" name="_redirect" value="{{ route('payroll.loans.index') }}">
            <div class="modal-top-actions">
                <h3>Edit Loan Provider</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-provider-modal').close()">✕</button>
            </div>
            <div class="form-group">
                <label for="ep-type">Type / Name</label>
                <input type="text" name="type" id="ep-type" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="ep-provider">Provider / Bank</label>
                <input type="text" name="provider" id="ep-provider" class="form-input">
            </div>
            <div class="form-group">
                <label for="ep-desc">Description</label>
                <textarea name="description" id="ep-desc" class="form-input" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-provider-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Update</button>
            </div>
        </form>
    </dialog>
@endsection

@section('page_scripts_after')
<script>
function openEditLoan(deductionId, id, balance, monthlyPayment, status) {
    document.getElementById('edit-loan-form').action =
        '{{ url("payroll-manager/contributions") }}/' + deductionId + '/loans/' + id;
    document.getElementById('edit-loan-balance').value = balance;
    document.getElementById('edit-loan-monthly').value = monthlyPayment;
    document.getElementById('edit-loan-status').value = status;
    document.getElementById('edit-loan-modal').showModal();
}

function openLoanHistory(employeeName, history) {
    document.getElementById('loan-history-title').textContent = 'Billing History — ' + employeeName;
    const tbody = document.getElementById('loan-history-body');
    tbody.innerHTML = '';
    history.forEach(function (h) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + h.month + '</td><td>₱' + h.balance + '</td><td>₱' + h.monthly_payment + '</td>';
        tbody.appendChild(tr);
    });
    document.getElementById('loan-history-empty').style.display = history.length ? 'none' : '';
    document.getElementById('loan-history-modal').showModal();
}

function confirmDeleteLoan(deductionId, loanId) {
    const url = '{{ url("payroll-manager/contributions") }}/' + deductionId + '/loans/' + loanId;
    const run = () => {
        const form = document.getElementById('delete-loan-form');
        form.action = url;
        form.submit();
    };
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Remove this loan?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, Remove' })
            .then(r => { if (r.isConfirmed) run(); });
    } else if (confirm('Remove this loan?')) {
        run();
    }
}

function openEditProvider(id) {
    var row = document.getElementById('provider-row-' + id);
    if (!row) return;
    document.getElementById('edit-provider-form').action = '{{ url("payroll-manager/contributions") }}/' + id;
    document.getElementById('ep-type').value = row.dataset.type;
    document.getElementById('ep-provider').value = row.dataset.provider;
    document.getElementById('ep-desc').value = row.dataset.description;
    document.getElementById('edit-provider-modal').showModal();
}

function confirmToggleProviderActive(button) {
    var form = button.closest('form');
    var isActivate = form.dataset.action === 'activate';
    var name = form.dataset.name || 'this provider';
    var run = function () { form.submit(); };
    var html = isActivate
        ? '<b>' + name + '</b> will be assignable to new loans and billing uploads again.'
        : '<b>' + name + '</b> will be hidden from new loan assignment/billing upload. Existing loans keep being deducted as normal.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: isActivate ? 'question' : 'warning',
            title: isActivate ? 'Activate this provider?' : 'Deactivate this provider?',
            html: html,
            showCancelButton: true,
            confirmButtonText: isActivate ? 'Yes, activate' : 'Yes, deactivate',
        }).then(function (r) { if (r.isConfirmed) run(); });
    } else if (confirm(isActivate ? 'Activate this provider?' : 'Deactivate this provider?')) {
        run();
    }
}

function confirmDeleteProvider(id) {
    const run = () => document.getElementById('delete-provider-' + id).submit();
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete this provider?', text: 'Only possible if no loans (active or historical) are recorded under it.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(r => { if (r.isConfirmed) run(); });
    } else if (confirm('Delete this provider?')) {
        run();
    }
}

@if ($errors->any())
    document.getElementById('add-provider-modal')?.showModal();
@endif
</script>
@endsection
