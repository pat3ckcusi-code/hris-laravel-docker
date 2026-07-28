@extends('dashboards.layout', [
    'title' => $deduction->type,
    'subtitle' => $deduction->deduction_category === 'loan' ? 'Loan provider details.' : 'Deduction type details and employee assignments.',
])

@section('top_actions')
    <div class="header-actions">
        @if($deduction->isWithholdingTax())
            <a href="{{ route('payroll.withholding-tax.template', ['year' => $withholdingSelectedYear]) }}" class="btn btn-sm btn-outline"><i class="fas fa-download"></i> Download Template</a>
            <button type="button" class="btn btn-sm" onclick="document.getElementById('uploadWithholdingTaxModal').showModal()"><i class="fas fa-upload"></i> Upload Withholding Tax</button>
        @else
            @if($deduction->deduction_category === 'loan' && $deduction->is_active)
                <button type="button" class="btn btn-sm" onclick="document.getElementById('assign-loan-modal').showModal()">
                    <i class="fas fa-plus"></i> Assign Loan
                </button>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('bulk-assign-loan-modal').showModal()">
                    <i class="fas fa-users"></i> Add to Roster
                </button>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('upload-billing-modal').showModal()">
                    <i class="fas fa-upload"></i> Upload Billing
                </button>
            @elseif($deduction->deduction_category === 'other' && $deduction->is_active && ! $deduction->isAutoComputed())
                <button type="button" class="btn btn-sm" onclick="document.getElementById('assign-modal').showModal()">
                    <i class="fas fa-plus"></i> Assign Employee(s)
                </button>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-by-type-modal').showModal()">
                    <i class="fas fa-users"></i> Assign by Type
                </button>
            @endif
            @if($deduction->isAutoComputed())
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-eligibility-modal').showModal()">
                    Assign Employee Types
                </button>
            @endif
            <form method="POST" action="{{ route('payroll.contributions.toggle-active', $deduction->id) }}"
                  id="toggle-active-form" data-action="{{ $deduction->is_active ? 'deactivate' : 'activate' }}"
                  data-mandatory="{{ $deduction->mandatory_key ? '1' : '0' }}"
                  data-name="{{ $deduction->type }}" style="display:inline">
                @csrf @method('PUT')
                <button type="button" class="btn btn-sm btn-outline" onclick="confirmToggleActive(document.getElementById('toggle-active-form'))">
                    {{ $deduction->is_active ? 'Deactivate' : 'Activate' }}
                </button>
            </form>
        @endif
        <a href="{{ $deduction->deduction_category === 'loan' ? route('payroll.loans.index') : route('payroll.contributions.index') }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    @php
        $categoryIcon = $deduction->mandatory_key ? 'fa-landmark' : ($deduction->deduction_category === 'loan' ? 'fa-hand-holding-dollar' : 'fa-receipt');
        $categoryColors = $deduction->mandatory_key
            ? ['bg' => '#ede9fe', 'border' => '#ddd6fe', 'ink' => '#5b21b6']
            : ($deduction->deduction_category === 'loan'
                ? ['bg' => '#dbeafe', 'border' => '#bfdbfe', 'ink' => '#1e40af']
                : ['bg' => '#e0f2fe', 'border' => '#bae6fd', 'ink' => '#075985']);
        $categoryLabel = $deduction->mandatory_key ? 'Mandatory' : ucfirst($deduction->deduction_category ?? '-');

        $currentRate = null;
        if ($deduction->mandatory_key) {
            $mc = $deduction->mandatory_config ?? [];
            $currentRate = match ($deduction->computation_type) {
                'flat' => '₱'.number_format($mc['amount'] ?? 0, 2).' flat',
                'bracket' => count($mc['brackets'] ?? []).'-tier bracket table',
                default => number_format(($mc['rate'] ?? 0) * 100, 2).'% of Basic Salary',
            };
        }
    @endphp

    @unless($deduction->isWithholdingTax())
        <div class="profile-card">
            <div class="profile-avatar" style="background:{{ $categoryColors['bg'] }};border-color:{{ $categoryColors['border'] }};color:{{ $categoryColors['ink'] }}">
                <i class="fas {{ $categoryIcon }}"></i>
            </div>
            <div class="profile-body">
                <div class="profile-name">{{ $deduction->type }}</div>
                @if($deduction->description || $deduction->provider)
                    <div class="profile-position">{{ $deduction->description ?: $deduction->provider }}</div>
                @endif
                <div class="profile-meta">
                    <span class="meta-chip" style="background:{{ $categoryColors['bg'] }};border-color:{{ $categoryColors['border'] }};color:{{ $categoryColors['ink'] }}">
                        <i class="fas {{ $categoryIcon }}" style="color:{{ $categoryColors['ink'] }}"></i> {{ $categoryLabel }}
                    </span>
                    <span class="meta-chip">
                        <i class="fas {{ $deduction->is_active ? 'fa-circle-check' : 'fa-circle-xmark' }}" style="color:{{ $deduction->is_active ? '#166534' : '#991b1b' }}"></i>
                        {{ $deduction->is_active ? 'Active' : 'Inactive' }}
                    </span>
                    @if($currentRate)
                        <span class="meta-chip"><i class="fas fa-calculator"></i> {{ $currentRate }}</span>
                    @endif
                    @if($deduction->mandatory_key)
                        @if($deduction->eligible_employee_types)
                            @foreach($deduction->eligible_employee_types as $type)
                                <span class="meta-chip"><i class="fas fa-user-check"></i> {{ $type }}</span>
                            @endforeach
                        @else
                            <span class="meta-chip"><i class="fas fa-users"></i> All employee types</span>
                        @endif
                    @endif
                    @if($deduction->provider && $deduction->description)
                        <span class="meta-chip"><i class="fas fa-building"></i> {{ $deduction->provider }}</span>
                    @endif
                    @if($deduction->formula)
                        <span class="meta-chip"><i class="fas fa-note-sticky"></i> {{ $deduction->formula }}</span>
                    @endif
                </div>
            </div>
        </div>
    @endunless

    @if($deduction->isWithholdingTax())
        <section class="payroll-section">
            <h2><i class="fas fa-table"></i> Withholding Tax Table</h2>

            <form method="GET" action="{{ route('payroll.contributions.show', $deduction->id) }}" class="plantilla-filter-form" style="margin-bottom:14px">
                <select name="year" class="hris-filter-select" onchange="this.form.submit()">
                    @forelse($withholdingYears as $y)
                        <option value="{{ $y }}" @selected((int) $y === $withholdingSelectedYear)>{{ $y }}</option>
                    @empty
                        <option value="{{ $withholdingSelectedYear }}">{{ $withholdingSelectedYear }}</option>
                    @endforelse
                </select>
                <select name="type" class="hris-filter-select" onchange="this.form.submit()">
                    <option value="">All Employee Types</option>
                    @foreach($employeeTypes as $type)
                        <option value="{{ $type }}" @selected($withholdingType === $type)>{{ $type }}</option>
                    @endforeach
                </select>
                <input type="text" name="search" value="{{ $withholdingSearch }}" placeholder="Search employee name or Employee Agency Number..." class="hris-search-input" style="min-width:260px">
                <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-filter"></i> Filter</button>
                @if($withholdingSearch !== '' || $withholdingType !== '')
                    <a href="{{ route('payroll.contributions.show', ['contribution' => $deduction->id, 'year' => $withholdingSelectedYear]) }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
                @endif
            </form>

            @if($withholdingEmployees->count())
                <div class="plantilla-panel overflow-x-auto">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                @foreach(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $monthLabel)
                                    <th>{{ $monthLabel }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($withholdingEmployees as $wtEmployee)
                                @php $monthly = $withholdingEntries->get($wtEmployee->id, collect())->keyBy('month'); @endphp
                                <tr>
                                    <td>{{ $wtEmployee->name }}</td>
                                    @for($month = 1; $month <= 12; $month++)
                                        @php $entry = $monthly->get($month); @endphp
                                        <td>
                                            @if($entry)
                                                <span class="editable-cell" onclick="openEditWithholdingTax({{ $entry->id }}, '{{ addslashes($wtEmployee->name) }}', {{ $month }}, {{ $entry->amount }})" style="cursor:pointer" title="Click to edit">
                                                    ₱{{ number_format($entry->amount, 2) }}
                                                </span>
                                            @else
                                                <span class="editable-cell text-muted" onclick="openAddWithholdingTax({{ $wtEmployee->id }}, '{{ addslashes($wtEmployee->name) }}', {{ $month }}, {{ $withholdingSelectedYear }})" style="cursor:pointer" title="Click to add">
                                                    -
                                                </span>
                                            @endif
                                        </td>
                                    @endfor
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-hris.table-pagination :paginator="$withholdingEmployees" />
            @else
                <p class="empty-state"><i class="fas fa-table" style="display:block;font-size:1.5rem;margin-bottom:8px;color:#cbd5e1"></i>No employees match your search.</p>
            @endif
        </section>
    @endif

    @unless($deduction->is_active || $deduction->isWithholdingTax())
        @if($deduction->mandatory_key)
            <p class="notice error" style="margin-bottom:12px">⚠ This mandatory government deduction is INACTIVE — it is not being withheld from any employee's pay on any payroll run. Reactivate it to resume withholding.</p>
        @else
            <p class="empty-state" style="margin-bottom:12px">This deduction type is inactive — new employees can't be assigned to it. Existing employees keep being deducted as normal.</p>
        @endif
    @endunless

    @if($deduction->supportsRateConfiguration() && ! $deduction->isWithholdingTax())
        @php
            $mcDefaultType = $deduction->mandatory_key ? 'percentage' : 'individual';
            $mcType = old('computation_type', $deduction->computation_type ?? $mcDefaultType);
            $mcConfig = $deduction->mandatory_config ?? [];
            $dormantCount = $deduction->isAutoComputed() ? $deduction->employeeDeductions->count() : 0;
        @endphp
        <section class="payroll-section">
            <h2><i class="fas fa-sliders"></i> Rate Configuration</h2>
            @if($deduction->mandatory_key)
                <p class="empty-state" style="margin-bottom:12px">This is a system mandatory government deduction — the computation type and rate below directly change how it's computed on every future payroll run. Switching computation type (e.g. from Flat Amount to Percentage) needs no code change.</p>
            @else
                <p class="empty-state" style="margin-bottom:12px">Choose <strong>Individually Assigned</strong> (the default) to keep assigning specific employees with their own custom amount via "Assign Employee(s)"/"Assign by Type". Or choose a Standing Rate (Flat/Percentage/Bracket) to automatically charge every eligible employee the same amount on every future payroll run — no per-employee assignment needed at all. Switching modes never deletes existing individual assignments; they just go inactive while a Standing Rate is in effect, and resume automatically if you switch back.</p>
            @endif
            @if($dormantCount > 0)
                <p class="notice error" style="margin-bottom:12px">⚠ {{ $dormantCount }} employee(s) have an individually-assigned amount for this deduction, but {{ $dormantCount === 1 ? 'it is' : 'they are' }} currently INACTIVE while a Standing Rate is in effect. Switch back to "Individually Assigned" above to restore {{ $dormantCount === 1 ? 'it' : 'them' }}.</p>
            @endif

            <form method="POST" action="{{ route('payroll.contributions.mandatory-config.update', $deduction->id) }}" class="payroll-form" id="mandatory-config-form">
                @csrf @method('PUT')

                <div class="form-group">
                    <label>Computation Type</label>
                    <div class="computation-type-picker">
                        @unless($deduction->mandatory_key)
                            <label class="computation-type-option">
                                <input type="radio" name="computation_type" value="individual" @checked($mcType === 'individual') onchange="switchComputationType(this.value)">
                                <span class="ct-icon"><i class="fas fa-user-check"></i></span>
                                <span class="ct-label">Individually Assigned</span>
                                <span class="ct-desc">Per-employee custom amount</span>
                            </label>
                        @endunless
                        <label class="computation-type-option">
                            <input type="radio" name="computation_type" value="flat" @checked($mcType === 'flat') onchange="switchComputationType(this.value)">
                            <span class="ct-icon"><i class="fas fa-coins"></i></span>
                            <span class="ct-label">Flat Amount</span>
                            <span class="ct-desc">A fixed peso amount</span>
                        </label>
                        <label class="computation-type-option">
                            <input type="radio" name="computation_type" value="percentage" @checked($mcType === 'percentage') onchange="switchComputationType(this.value)">
                            <span class="ct-icon"><i class="fas fa-percent"></i></span>
                            <span class="ct-label">Percentage</span>
                            <span class="ct-desc">% of Basic Salary</span>
                        </label>
                        <label class="computation-type-option">
                            <input type="radio" name="computation_type" value="bracket" @checked($mcType === 'bracket') onchange="switchComputationType(this.value)">
                            <span class="ct-icon"><i class="fas fa-layer-group"></i></span>
                            <span class="ct-label">Bracket / Tiered</span>
                            <span class="ct-desc">Tax-table style tiers</span>
                        </label>
                    </div>
                </div>

                <div id="mc-flat-fields" class="mc-fields">
                    <div class="form-group">
                        <label for="mc-amount">Flat Amount</label>
                        <div class="input-affix">
                            <span class="input-affix-icon">₱</span>
                            <input type="text" inputmode="decimal" name="amount" id="mc-amount" class="form-input currency-input"
                                   value="{{ old('amount', $mcConfig['amount'] ?? '') }}" required>
                        </div>
                    </div>
                </div>

                <div id="mc-percentage-fields" class="mc-fields">
                    <div class="form-group">
                        <label for="mc-rate">Rate</label>
                        <div class="input-affix suffix">
                            <input type="number" name="rate_percent" id="mc-rate" class="form-input" min="0" max="100" step="0.01"
                                   value="{{ old('rate_percent', isset($mcConfig['rate']) ? $mcConfig['rate'] * 100 : '') }}" required>
                            <span class="input-affix-icon">%</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="mc-floor">Floor — optional, leave blank for no minimum</label>
                        <div class="input-affix">
                            <span class="input-affix-icon">₱</span>
                            <input type="text" inputmode="decimal" name="floor" id="mc-floor" class="form-input currency-input"
                                   value="{{ old('floor', $mcConfig['floor'] ?? '') }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="mc-ceiling">Ceiling — optional, leave blank for no maximum</label>
                        <div class="input-affix">
                            <span class="input-affix-icon">₱</span>
                            <input type="text" inputmode="decimal" name="ceiling" id="mc-ceiling" class="form-input currency-input"
                                   value="{{ old('ceiling', $mcConfig['ceiling'] ?? '') }}">
                        </div>
                    </div>
                </div>

                <div id="mc-bracket-fields" class="mc-fields">
                    <div class="hris-table-wrapper">
                    <table class="hris-table" id="bir-brackets-table">
                        <thead>
                            <tr>
                                <th>Min (₱)</th>
                                <th>Max (₱, optional)</th>
                                <th>Base (₱)</th>
                                <th>Marginal Rate (%)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="bir-brackets-body">
                            @foreach(($mcConfig['brackets'] ?? []) as $i => $bracket)
                                <tr class="bir-bracket-row">
                                    <td><input type="text" inputmode="decimal" name="brackets[{{ $i }}][min]" class="form-input currency-input" value="{{ $bracket['min'] }}" required></td>
                                    <td><input type="text" inputmode="decimal" name="brackets[{{ $i }}][max]" class="form-input currency-input" value="{{ $bracket['max'] }}"></td>
                                    <td><input type="text" inputmode="decimal" name="brackets[{{ $i }}][base]" class="form-input currency-input" value="{{ $bracket['base'] }}" required></td>
                                    <td><input type="number" name="brackets[{{ $i }}][rate_percent]" class="form-input" min="0" step="0.01" value="{{ $bracket['rate'] * 100 }}" required></td>
                                    <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">✕</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                    <div style="margin-top:8px">
                        <button type="button" class="btn btn-sm btn-outline" onclick="addBirBracketRow()">+ Add Bracket</button>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-sm">Save Configuration</button>
                </div>
            </form>
        </section>
    @endif

    {{-- Assign Employee Types modal (mandatory rows except BIR, and "other" rows in Standing Rate mode) --}}
    @if($deduction->isAutoComputed() && ! $deduction->isWithholdingTax())
        <dialog id="assign-eligibility-modal" class="employee-modal">
            <form method="POST" action="{{ route('payroll.contributions.eligibility.update', $deduction->id) }}" class="payroll-form">
                @csrf @method('PUT')
                <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
                    <div>
                        <h3 style="margin:0"><i class="fas fa-user-check" style="color:var(--accent);margin-right:8px"></i>Assign Employee Types</h3>
                        <span class="record-email">Choose which employee types {{ $deduction->type }} applies to</span>
                    </div>
                    <button type="button" class="modal-close" aria-label="Close" onclick="document.getElementById('assign-eligibility-modal').close()">✕</button>
                </div>
                <p class="empty-state" style="text-align:left;padding:0;margin:16px 0 20px">
                    <i class="fas fa-circle-info" style="color:var(--muted);margin-right:6px"></i>
                    Uncheck a type to exclude it from {{ $deduction->type }} entirely — e.g. Job Orders are typically not GSIS members. An excluded employee is charged ₱0 for this program and it's omitted from their payslip. At least one type must stay checked.
                </p>

                @error('employee_types')
                    <div class="notice error">{{ $message }}</div>
                @enderror

                @php $eligibleTypes = $deduction->eligible_employee_types; @endphp
                <div class="form-group">
                    @foreach($employeeTypes as $type)
                        <label class="checkbox-label" style="display:flex;margin-bottom:12px;font-weight:700">
                            <input type="checkbox" name="employee_types[]" value="{{ $type }}"
                                   @checked($eligibleTypes === null || in_array($type, $eligibleTypes, true))>
                            {{ $type }}
                        </label>
                    @endforeach
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-eligibility-modal').close()">Cancel</button>
                    <button type="submit" class="btn btn-sm">Save</button>
                </div>
            </form>
        </dialog>
    @endif

    {{-- Assign by Employee Type modal (other recurring deduction) - quick
         bulk-assign shortcut mirroring the Mandatory row's simple "Assign
         Employee Types" checkbox-list UI above, for when every employee of a
         type should get the same amount instead of picking individuals. --}}
    @if($deduction->deduction_category === 'other' && $deduction->is_active && ! $deduction->isAutoComputed())
        <dialog id="assign-by-type-modal" class="employee-modal">
            <form method="POST" action="{{ route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id) }}" class="payroll-form">
                @csrf
                <div class="modal-top-actions">
                    <h3>Assign by Employee Type</h3>
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-by-type-modal').close()">✕</button>
                </div>
                <p class="empty-state" style="margin-bottom:12px">Assigns this deduction to every active employee of the checked type(s), all at the same amount. Anyone already assigned is skipped — their existing amount is left untouched.</p>

                @error('employee_types')
                    <div class="notice error">{{ $message }}</div>
                @enderror

                <div class="form-group">
                    @foreach($employeeTypes as $type)
                        <label class="checkbox-label" style="display:block;margin-bottom:6px">
                            <input type="checkbox" name="employee_types[]" value="{{ $type }}">
                            {{ $type }}
                        </label>
                    @endforeach
                </div>

                <div class="form-group">
                    <label for="abt-amount">Amount</label>
                    <div class="input-affix">
                        <span class="input-affix-icon">₱</span>
                        <input type="text" inputmode="decimal" name="amount" id="abt-amount" class="form-input currency-input" required placeholder="e.g. 100.00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="recurring" value="1" checked> Recurring (monthly)
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-by-type-modal').close()">Cancel</button>
                    <button type="submit" class="btn btn-sm">Save</button>
                </div>
            </form>
        </dialog>
    @endif

    @if($deduction->deduction_category === 'other' && ! $deduction->isAutoComputed())
        <section class="payroll-section">
            <h2><i class="fas fa-users"></i> Employee Deductions</h2>
            @if($deduction->employeeDeductions->count())
                <div class="hris-table-wrapper">
                <table class="hris-table">
                    <thead><tr><th>Employee</th><th>Amount</th><th>Recurring</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach($deduction->employeeDeductions as $ed)
                            <tr>
                                <td>{{ $ed->employee->name ?? '-' }}</td>
                                <td>₱{{ number_format($ed->amount, 2) }}</td>
                                <td>{{ $ed->recurring ? 'Yes' : 'No' }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline"
                                        onclick="openEditEmployeeDeduction({{ $ed->id }}, '{{ $ed->amount }}', {{ $ed->recurring ? 'true' : 'false' }})">Edit</button>
                                    <button type="button" class="btn btn-sm btn-danger"
                                        onclick="confirmDeleteEmployeeDeduction({{ $deduction->id }}, {{ $ed->id }})">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @else
                <p class="empty-state">No employee deductions.</p>
            @endif
        </section>
    @endif

    @if($deduction->deduction_category === 'loan')
        <section class="payroll-section">
            <h2><i class="fas fa-hand-holding-dollar"></i> Active Loans</h2>
            @if($deduction->loans->count())
                <div class="hris-table-wrapper">
                <table class="hris-table">
                    <thead><tr><th>Employee</th><th>Balance</th><th>Monthly</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach($deduction->loans as $loan)
                            <tr>
                                <td>{{ $loan->employee->name ?? '-' }}</td>
                                <td>₱{{ number_format($loan->balance, 2) }}</td>
                                <td>₱{{ number_format($loan->monthly_payment, 2) }}</td>
                                <td><span class="status-chip status-{{ $loan->status }}">{{ ucfirst($loan->status) }}</span></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline"
                                        onclick="openEditLoan({{ $loan->id }}, '{{ $loan->balance }}', '{{ $loan->monthly_payment }}', '{{ $loan->status }}')">Edit</button>
                                    <button type="button" class="btn btn-sm btn-outline"
                                        onclick='openLoanHistory("{{ $loan->employee->name ?? "-" }}", {{ $loan->billingHistory->map(fn ($h) => ["month" => $h->billing_month->format("F Y"), "balance" => number_format($h->balance, 2), "monthly_payment" => number_format($h->monthly_payment, 2)])->toJson() }})'>History</button>
                                    <button type="button" class="btn btn-sm btn-danger"
                                        onclick="confirmDeleteLoan({{ $deduction->id }}, {{ $loan->id }})">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @else
                <p class="empty-state">No active loans under this deduction.</p>
            @endif
        </section>
    @endif

    {{-- Assign Loan modal (single employee) - never rendered for a non-loan row or an inactive type, so it can't be reached even by directly toggling the underlying <dialog> --}}
    @if($deduction->deduction_category === 'loan' && $deduction->is_active)
    <dialog id="assign-loan-modal" class="employee-modal">
        <form method="POST" action="{{ route('payroll.contributions.loans.store', $deduction->id) }}" class="payroll-form">
            @csrf
            <div class="modal-top-actions">
                <h3>Assign Loan</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-loan-modal').close()">✕</button>
            </div>
            <div class="form-group">
                <label for="loan-employee">Employee</label>
                <select name="employee_id" id="loan-employee" class="form-input" required>
                    <option value="">- Select employee -</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" @disabled(in_array($emp->id, $loanedEmployeeIds))>
                            {{ $emp->name }}{{ in_array($emp->id, $loanedEmployeeIds) ? ' (already has this loan)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="loan-balance">Balance</label>
                <div class="input-affix">
                    <span class="input-affix-icon">₱</span>
                    <input type="text" inputmode="decimal" name="balance" id="loan-balance" class="form-input currency-input" required>
                </div>
            </div>
            <div class="form-group">
                <label for="loan-monthly">Monthly Payment</label>
                <div class="input-affix">
                    <span class="input-affix-icon">₱</span>
                    <input type="text" inputmode="decimal" name="monthly_payment" id="loan-monthly" class="form-input currency-input" required>
                </div>
            </div>
            <div class="form-group">
                <label for="loan-status">Status</label>
                <select name="status" id="loan-status" class="form-input" required>
                    <option value="active">Active</option>
                    <option value="paid">Paid</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assign-loan-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Save</button>
            </div>
        </form>
    </dialog>
    @endif

    {{-- Add to Roster modal (bulk, no balance/monthly payment yet) - never rendered for a non-loan row or an inactive type --}}
    @if($deduction->deduction_category === 'loan' && $deduction->is_active)
    <dialog id="bulk-assign-loan-modal" class="employee-modal modal-lg">
        <form method="POST" action="{{ route('payroll.contributions.loans.bulk-assign', $deduction->id) }}" class="payroll-form">
            @csrf
            <div class="modal-top-actions">
                <h3>Add to Roster</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('bulk-assign-loan-modal').close()">✕</button>
            </div>

            @error('employee_ids')
                <div class="notice error">{{ $message }}</div>
            @enderror

            <p class="empty-state" style="margin-bottom:12px;text-align:left;padding:0">
                Adds a placeholder loan (₱0 balance) for each selected employee so they appear on the next billing
                template with their Employee Agency Number, Name, and Department already filled in — fill in their
                real Monthly Payment and Balance there instead of here.
            </p>

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
                    <input type="text" id="assign-emp-search" class="form-input" style="max-width:240px" placeholder="Search name or Employee Agency Number…" oninput="filterAssignEmployees()">
                    <label class="checkbox-label">
                        <input type="checkbox" id="assign-select-all" onchange="toggleAssignSelectAll(this.checked)"> Select all visible
                    </label>
                    <span id="assign-selected-count" style="font-size:.8rem;color:#64748b">0 selected</span>
                </div>

                <div id="assign-emp-list" style="max-height:280px;overflow:auto;border:1px solid #e2e8f0;border-radius:6px;margin-top:8px;padding:6px 10px">
                    @forelse($employees as $emp)
                        @php $alreadyAssigned = in_array($emp->id, $loanedEmployeeIds); @endphp
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
                                @if($alreadyAssigned)<span class="status-chip" style="margin-left:6px">Already on roster</span>@endif
                            </label>
                        </div>
                    @empty
                        <p class="empty-state">No employees found.</p>
                    @endforelse
                </div>
                <p id="assign-emp-empty-state" class="empty-state" style="display:none">No employees match this filter.</p>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('bulk-assign-loan-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Save</button>
            </div>
        </form>
    </dialog>
    @endif

    {{-- Upload Billing modal (bulk, one provider at a time) - never rendered for an inactive type --}}
    @if($deduction->is_active)
    <dialog id="upload-billing-modal" class="employee-modal">
        <form method="POST" action="{{ route('payroll.contributions.loans.billing.upload', $deduction->id) }}" enctype="multipart/form-data" class="payroll-form" onsubmit="return confirmUploadBilling(event)">
            @csrf
            <div class="modal-top-actions">
                <h3>Upload Billing</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('upload-billing-modal').close()">✕</button>
            </div>
            <div style="margin-bottom:12px">
                <a id="download-template-link"
                   href="{{ route('payroll.contributions.loans.billing-template', ['contribution' => $deduction->id, 'month' => now()->format('Y-m')]) }}"
                   class="btn btn-sm btn-outline">
                    <i class="fas fa-download"></i> Download Template
                </a>
                <p class="empty-state" style="margin:8px 0 0;padding:0;text-align:left">
                    Pre-filled with this provider's current employees (Employee Agency Number, Name, Department) and
                    the Billing Month printed at the top of the sheet — just fill in Monthly Payment and Balance next
                    to the right name. An employee already on this provider has their balance/monthly payment updated
                    to this month's figures; add a new row for an Employee Agency Number not yet assigned. Use "Add to
                    Roster" first if an employee isn't listed yet.
                </p>
            </div>
            <div class="form-group">
                <label for="billing-month">Billing Month</label>
                <input type="month" name="billing_month" id="billing-month" class="form-input" required value="{{ now()->format('Y-m') }}">
            </div>
            <div class="form-group">
                <label for="billing-file">Billing File (xlsx, xls, or csv)</label>
                <input type="file" name="billing_file" id="billing-file" class="form-input" accept=".xlsx,.xls,.csv" required>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('upload-billing-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Upload</button>
            </div>
        </form>
    </dialog>
    @endif

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

    {{-- Edit Loan modal --}}
    <dialog id="edit-loan-modal" class="employee-modal">
        <form method="POST" id="edit-loan-form" class="payroll-form">
            @csrf @method('PUT')
            <div class="modal-top-actions">
                <h3>Edit Loan</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-loan-modal').close()">✕</button>
            </div>
            <div class="form-group">
                <label for="edit-loan-balance">Balance</label>
                <div class="input-affix">
                    <span class="input-affix-icon">₱</span>
                    <input type="text" inputmode="decimal" name="balance" id="edit-loan-balance" class="form-input currency-input" required>
                </div>
            </div>
            <div class="form-group">
                <label for="edit-loan-monthly">Monthly Payment</label>
                <div class="input-affix">
                    <span class="input-affix-icon">₱</span>
                    <input type="text" inputmode="decimal" name="monthly_payment" id="edit-loan-monthly" class="form-input currency-input" required>
                </div>
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
    </form>

    {{-- Bulk Assign modal (other recurring deduction) - never rendered for a non-"other" row, an inactive type, or once the type has switched to Standing Rate mode --}}
    @if($deduction->deduction_category === 'other' && $deduction->is_active && ! $deduction->isAutoComputed())
    <dialog id="assign-modal" class="employee-modal modal-lg">
        <form method="POST" action="{{ route('payroll.contributions.employee-deductions.store', $deduction->id) }}" class="payroll-form">
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
                <label for="od-amount">Amount</label>
                <div class="input-affix">
                    <span class="input-affix-icon">₱</span>
                    <input type="text" inputmode="decimal" name="amount" id="od-amount" class="form-input currency-input" required placeholder="e.g. 100.00">
                </div>
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
    @endif

    {{-- Edit EmployeeDeduction modal --}}
    <dialog id="edit-ed-modal" class="employee-modal">
        <form method="POST" id="edit-ed-form" class="payroll-form">
            @csrf @method('PUT')
            <div class="modal-top-actions">
                <h3>Edit Deduction Assignment</h3>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-ed-modal').close()">✕</button>
            </div>
            <div class="form-group">
                <label for="edit-ed-amount">Amount</label>
                <div class="input-affix">
                    <span class="input-affix-icon">₱</span>
                    <input type="text" inputmode="decimal" name="amount" id="edit-ed-amount" class="form-input currency-input" required>
                </div>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="recurring" id="edit-ed-recurring" value="1"> Recurring (monthly)
                </label>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-ed-modal').close()">Cancel</button>
                <button type="submit" class="btn btn-sm">Update</button>
            </div>
        </form>
    </dialog>

    {{-- Hidden delete-employee-deduction form --}}
    <form id="delete-ed-form" method="POST" style="display:none">
        @csrf @method('DELETE')
    </form>

    @if($deduction->isWithholdingTax())
        <dialog id="editWithholdingTaxModal" class="employee-modal">
            <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
                <div>
                    <h3 style="margin:0" id="wt-modal-title">Edit Withholding Tax Entry</h3>
                    <span class="record-email" id="edit-wt-subtitle">Update amount</span>
                </div>
                <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
            </div>

            <form method="POST" id="editWithholdingTaxForm" class="payroll-form" style="margin-top:12px">
                @csrf
                <input type="hidden" name="_method" id="wt-method-field" value="PUT">
                <input type="hidden" name="employee_id" id="wt-employee-id">
                <input type="hidden" name="year" id="wt-year">
                <input type="hidden" name="month" id="wt-month">
                <div class="form-group">
                    <label for="wt-amount">Amount (₱)</label>
                    <input type="text" inputmode="decimal" name="amount" id="wt-amount" class="form-input currency-input" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Update</button>
                    <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="uploadWithholdingTaxModal" class="employee-modal">
            <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
                <div>
                    <h3 style="margin:0"><i class="fas fa-upload" style="color:var(--accent);margin-right:8px"></i>Upload Withholding Tax</h3>
                    <span class="record-email">Upload Accounting's monthly figures for a year</span>
                </div>
                <button type="button" class="modal-close" aria-label="Close" onclick="document.getElementById('uploadWithholdingTaxModal').close()">✕</button>
            </div>

            <form method="POST" action="{{ route('payroll.withholding-tax.upload') }}" enctype="multipart/form-data" class="payroll-form" style="margin-top:12px" onsubmit="return confirmUploadWithholdingTax(event)">
                @csrf
                <div class="form-group">
                    <label for="wt-upload-year">Year</label>
                    <input type="number" name="year" id="wt-upload-year" value="{{ $withholdingSelectedYear }}" min="2000" max="2100" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="wt-upload-file">File (.xlsx, .xls, .csv)</label>
                    <input type="file" name="withholding_tax_file" id="wt-upload-file" accept=".xlsx,.xls,.csv" class="form-input" required>
                </div>
                <p class="empty-state" style="text-align:left;padding:0;font-size:0.82rem">
                    <i class="fas fa-circle-info" style="margin-right:6px"></i>
                    Download the template first so Employee Agency Number/Name are already filled in — only the month
                    columns need Accounting's figures. Re-uploading updates existing entries for that year; a blank
                    month cell in the file is left untouched.
                </p>
                <div class="form-actions">
                    <button type="submit" class="btn"><i class="fas fa-upload"></i> Upload</button>
                    <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
                </div>
            </form>
        </dialog>
    @endif

    <div class="form-actions">
        <a href="{{ $deduction->deduction_category === 'loan' ? route('payroll.loans.index') : route('payroll.contributions.index') }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection

@section('page_scripts_after')
<script>
(function () {
    var billingMonthInput = document.getElementById('billing-month');
    var downloadTemplateLink = document.getElementById('download-template-link');
    if (billingMonthInput && downloadTemplateLink) {
        var templateBaseUrl = '{{ route("payroll.contributions.loans.billing-template", $deduction->id) }}';
        billingMonthInput.addEventListener('change', function () {
            downloadTemplateLink.href = templateBaseUrl + '?month=' + this.value;
        });
    }
})();

// Peso-value inputs (.currency-input) display comma-grouped, 2-decimal
// values (e.g. "183,541.80") while not focused, and their raw editable
// number while focused - since <input type="number"> can't contain commas
// at all, these are plain text inputs with the formatting handled here.
// Delegated on document (not attached per-element) so it also covers
// bracket rows added later by addBirBracketRow().
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
// (e.g. confirmUploadBilling) reads field values or the browser serializes
// the form - a blur just before submit would otherwise leave commas in.
document.addEventListener('submit', function (e) {
    if (!e.target.querySelectorAll) { return; }
    e.target.querySelectorAll('.currency-input').forEach(unformatCurrencyInput);
}, true);

function confirmUploadBilling(event) {
    event.preventDefault();
    var form = event.target;
    var monthInput = document.getElementById('billing-month');
    var fileInput = document.getElementById('billing-file');
    var monthLabel = monthInput && monthInput.value
        ? new Date(monthInput.value + '-01T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long' })
        : 'the selected month';
    var fileName = fileInput && fileInput.files.length ? fileInput.files[0].name : 'the selected file';
    var message = 'Upload "' + fileName + '" as the billing for ' + monthLabel + '? This updates balance/monthly payment for every matching employee in the file and creates new loans for any Employee Agency Number not yet on this provider.';

    if (typeof Swal !== 'undefined') {
        // Native <dialog> renders in the browser's top layer, which sits above
        // anything appended to document.body (Swal's default target) - point
        // Swal at the open dialog itself so its popup stacks inside it instead
        // of behind it.
        Swal.fire({
            title: 'Upload this billing file?', text: message, icon: 'question',
            showCancelButton: true, confirmButtonText: 'Yes, upload',
            target: document.getElementById('upload-billing-modal'),
        }).then(function (r) { if (r.isConfirmed) form.submit(); });
    } else if (confirm(message)) {
        form.submit();
    }
    return false;
}

function confirmToggleActive(form) {
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

function openEditLoan(id, balance, monthlyPayment, status) {
    document.getElementById('edit-loan-form').action =
        '{{ url("payroll-manager/contributions") }}/{{ $deduction->id }}/loans/' + id;
    var balanceInput = document.getElementById('edit-loan-balance');
    var monthlyInput = document.getElementById('edit-loan-monthly');
    balanceInput.value = balance;
    formatCurrencyInput(balanceInput);
    monthlyInput.value = monthlyPayment;
    formatCurrencyInput(monthlyInput);
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

function openEditEmployeeDeduction(id, amount, recurring) {
    document.getElementById('edit-ed-form').action =
        '{{ url("payroll-manager/contributions") }}/{{ $deduction->id }}/employee-deductions/' + id;
    var amountInput = document.getElementById('edit-ed-amount');
    amountInput.value = amount;
    formatCurrencyInput(amountInput);
    document.getElementById('edit-ed-recurring').checked = recurring;
    document.getElementById('edit-ed-modal').showModal();
}

function confirmDeleteEmployeeDeduction(deductionId, id) {
    const url = '{{ url("payroll-manager/contributions") }}/' + deductionId + '/employee-deductions/' + id;
    const run = () => {
        const form = document.getElementById('delete-ed-form');
        form.action = url;
        form.submit();
    };
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Remove this assignment?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, Remove' })
            .then(r => { if (r.isConfirmed) run(); });
    } else if (confirm('Remove this assignment?')) {
        run();
    }
}

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

    const countEl = document.getElementById('assign-selected-count');
    if (countEl) countEl.textContent = checkedCount + ' selected';

    const selectAllCb = document.getElementById('assign-select-all');
    if (selectAllCb) {
        selectAllCb.checked = visibleCheckboxes.length > 0 && checkedCount === visibleCheckboxes.length;
    }
}

var WT_MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function openEditWithholdingTax(id, employeeName, month, amount) {
    document.getElementById('editWithholdingTaxForm').action = '{{ url("payroll-manager/withholding-tax") }}/' + id;
    document.getElementById('wt-method-field').value = 'PUT';
    var amountInput = document.getElementById('wt-amount');
    amountInput.value = amount;
    formatCurrencyInput(amountInput);
    document.getElementById('wt-modal-title').textContent = 'Edit Withholding Tax Entry';
    document.getElementById('edit-wt-subtitle').textContent = employeeName + ' — ' + WT_MONTH_NAMES[month - 1];
    document.getElementById('editWithholdingTaxModal').showModal();
}

function openAddWithholdingTax(employeeId, employeeName, month, year) {
    document.getElementById('editWithholdingTaxForm').action = '{{ route("payroll.withholding-tax.store") }}';
    document.getElementById('wt-method-field').value = '';
    document.getElementById('wt-employee-id').value = employeeId;
    document.getElementById('wt-year').value = year;
    document.getElementById('wt-month').value = month;
    var amountInput = document.getElementById('wt-amount');
    amountInput.value = '';
    document.getElementById('wt-modal-title').textContent = 'Add Withholding Tax Entry';
    document.getElementById('edit-wt-subtitle').textContent = employeeName + ' — ' + WT_MONTH_NAMES[month - 1];
    document.getElementById('editWithholdingTaxModal').showModal();
}

function confirmUploadWithholdingTax(event) {
    event.preventDefault();
    var form = event.target;
    var year = document.getElementById('wt-upload-year').value;
    var fileInput = document.getElementById('wt-upload-file');
    var fileName = fileInput && fileInput.files.length ? fileInput.files[0].name : 'the selected file';
    var message = 'Upload "' + fileName + '" as withholding tax for ' + year + '? This updates existing entries for that year and adds any new ones — blank month cells in the file are left untouched.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Upload this file?', text: message, icon: 'question',
            showCancelButton: true, confirmButtonText: 'Yes, upload',
            target: document.getElementById('uploadWithholdingTaxModal'),
        }).then(function (r) { if (r.isConfirmed) form.submit(); });
    } else if (confirm(message)) {
        form.submit();
    }
    return false;
}

{{-- employee_ids/employee_types are each submitted by two mutually-exclusive
     forms (loan vs. other category; mandatory-eligibility vs. assign-by-type),
     so old() - which reflects exactly what the failed request submitted,
     regardless of which of its fields errored - disambiguates which modal to
     reopen; the ?. guard is a safe no-op for whichever dialog doesn't exist
     on this deduction's page. --}}
@if (old('employee_ids') !== null && $errors->any())
    document.getElementById('assign-modal')?.showModal();
    document.getElementById('bulk-assign-loan-modal')?.showModal();
@endif
@if (old('employee_types') !== null && $errors->any())
    document.getElementById('assign-eligibility-modal')?.showModal();
    document.getElementById('assign-by-type-modal')?.showModal();
@endif
@if ($errors->has('withholding_tax_file') || $errors->has('year'))
    document.getElementById('uploadWithholdingTaxModal')?.showModal();
@endif

let birBracketIndex = {{ count($deduction->mandatory_config['brackets'] ?? []) }};
function addBirBracketRow() {
    const tbody = document.getElementById('bir-brackets-body');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.className = 'bir-bracket-row';
    tr.innerHTML =
        '<td><input type="text" inputmode="decimal" name="brackets[' + birBracketIndex + '][min]" class="form-input currency-input" required></td>' +
        '<td><input type="text" inputmode="decimal" name="brackets[' + birBracketIndex + '][max]" class="form-input currency-input"></td>' +
        '<td><input type="text" inputmode="decimal" name="brackets[' + birBracketIndex + '][base]" class="form-input currency-input" required></td>' +
        '<td><input type="number" name="brackets[' + birBracketIndex + '][rate_percent]" class="form-input" min="0" step="0.01" required></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove()">✕</button></td>';
    tbody.appendChild(tr);
    birBracketIndex++;
}

// Toggles which computation-type field group is active, disabling inputs in
// the hidden groups so only the active group's fields submit - lets any of
// the 4 mandatory rows switch formula shape (flat/percentage/bracket) freely.
function switchComputationType(type) {
    document.querySelectorAll('.mc-fields').forEach(function (el) {
        el.style.display = 'none';
        el.querySelectorAll('input').forEach(function (input) { input.disabled = true; });
    });

    const active = document.getElementById('mc-' + type + '-fields');
    if (active) {
        active.style.display = '';
        active.querySelectorAll('input').forEach(function (input) { input.disabled = false; });
    }
}

const mcTypeChecked = document.querySelector('input[name="computation_type"]:checked');
if (mcTypeChecked) {
    switchComputationType(mcTypeChecked.value);
}
</script>
@endsection
