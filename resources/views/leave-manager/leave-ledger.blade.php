@extends('dashboards.layout', [
    'title'    => 'Leave Ledger',
    'subtitle' => 'Audit trail of leave credits and deductions per employee',
])

@section('content')

<section class="card">
    <header class="ll-page-header">
        <div class="ll-page-header-icon"><i class="fas fa-book-open"></i></div>
        <div>
            <h2>Leave Ledger</h2>
            <p class="ll-page-subtitle">Audit trail of leave credits and deductions per employee</p>
        </div>
    </header>

    <div class="card-body">

        {{-- Tabs --}}
        <div class="ll-tabs" role="tablist">
            <button type="button" class="ledger-tab-btn active" data-tab="tab-history">
                <i class="fas fa-clock-rotate-left"></i> Ledger History
            </button>
            <button type="button" class="ledger-tab-btn" data-tab="tab-monthly">
                <i class="fas fa-calendar-check"></i> Monthly Credits
            </button>
            <button type="button" class="ledger-tab-btn" data-tab="tab-awol">
                <i class="fas fa-triangle-exclamation"></i> AWOL Monitor
            </button>
        </div>

        {{-- ── Tab 1: Ledger History ── --}}
        <div id="tab-history" class="ledger-tab-panel">
            @php
                $employeesJs = $employees->map(fn($e) => [
                    'id'    => $e->id,
                    'empno' => $e->EmpNo ?? '',
                    'name'  => trim(($e->last_name ?? '') . ', ' . ($e->first_name ?? '')),
                    'dept'  => $departments[$e->Dept_id] ?? '',
                ])->values()->toArray();
            @endphp
            <script>
                var EXPORT_JOBS_URL = '{{ route('export-jobs.create') }}';
                var EMPLOYEES = @json($employeesJs);
            </script>

            {{-- Hidden field stores resolved user_id; all JS reads from here --}}
            <input type="hidden" id="ledger-employee">

            <div class="ll-filter-bar">
                <div class="ll-field ll-field--grow">
                    <label for="ledger-emp-input">Employee</label>
                    <div class="ll-input-icon-wrap">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="text" id="ledger-emp-input" class="ll-input" placeholder="Type name or EmpNo…" autocomplete="off">
                        <div id="ledger-emp-suggestions" class="ll-suggestions"></div>
                    </div>
                </div>
                <div class="ll-field">
                    <label for="ledger-year">Year</label>
                    <select id="ledger-year" class="ll-select">
                        <option value="">All years</option>
                        @foreach($years as $y)
                            <option value="{{ $y }}" @selected($y == $currentYear)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ll-field">
                    <label for="ledger-month">Month</label>
                    <select id="ledger-month" class="ll-select">
                        <option value="">All months</option>
                        @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                            <option value="{{ $mi + 1 }}" @selected($mi + 1 == now()->month)>{{ $mn }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ll-filter-actions">
                    <button type="button" id="ledger-load-btn" class="hris-btn-primary">
                        <i class="fas fa-magnifying-glass fa-fw"></i> Load
                    </button>
                    <button type="button" id="ledger-download-btn" class="hris-btn-secondary" disabled title="Select employee and month to enable">
                        <i class="fas fa-file-excel fa-fw"></i> Download Leave Card
                    </button>
                </div>
            </div>

            <div id="ledger-balance-summary" style="display:none;">
                <div class="ll-balance-summary">
                    <div class="ll-stat-card ll-stat-card--vl">
                        <div class="ll-stat-icon"><i class="fas fa-umbrella-beach"></i></div>
                        <div>
                            <div class="ll-stat-label">Vacation Leave Balance</div>
                            <div class="ll-stat-value"><span id="summary-vl">-</span></div>
                        </div>
                    </div>
                    <div class="ll-stat-card ll-stat-card--sl">
                        <div class="ll-stat-icon"><i class="fas fa-briefcase-medical"></i></div>
                        <div>
                            <div class="ll-stat-label">Sick Leave Balance</div>
                            <div class="ll-stat-value"><span id="summary-sl">-</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hris-table-card">
                <div class="hris-table-wrapper">
                    <table id="ledger-history-table" class="hris-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Period Date</th>
                                <th>Type</th>
                                <th>Leave Type</th>
                                <th class="text-right">Credit VL</th>
                                <th class="text-right">Credit SL</th>
                                <th class="text-right">Debit VL</th>
                                <th class="text-right">Debit SL</th>
                                <th class="text-right">VL Balance</th>
                                <th class="text-right">SL Balance</th>
                                <th>WOP Days</th>
                                <th>Remarks</th>
                                <th>Posted By</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── Tab 2: Monthly Credits ── --}}
        <div id="tab-monthly" class="ledger-tab-panel" style="display:none;">
            @unless($lastMonthProcessed)
            <div id="monthly-credit-reminder" class="lm-alert-banner">
                <i class="fas fa-triangle-exclamation fa-fw"></i>
                <span>{{ $lastMonthLabel }} leave credits have not been processed yet. Select it below and click <strong>Run Monthly Credits</strong>.</span>
            </div>
            @endunless

            <div class="ll-filter-bar">
                <div class="ll-field">
                    <label for="monthly-year">Year</label>
                    <select id="monthly-year" class="ll-select">
                        @foreach($years as $y)
                            <option value="{{ $y }}" @selected($y == $currentYear)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ll-field">
                    <label for="monthly-month">Month</label>
                    <select id="monthly-month" class="ll-select">
                        <option value="">All months</option>
                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                            <option value="{{ $mi + 1 }}" @selected($mi + 1 == $lastMonthMonth)>{{ $mn }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ll-filter-actions">
                    <button type="button" id="monthly-load-btn" class="hris-btn-secondary">
                        <i class="fas fa-magnifying-glass fa-fw"></i> Load
                    </button>
                    <button type="button" id="monthly-run-btn" class="hris-btn-primary">
                        <i class="fas fa-play fa-fw"></i> Run Monthly Credits
                    </button>
                    <button type="button" id="monthly-force-recompute-btn" class="hris-btn-secondary">
                        <i class="fas fa-rotate fa-fw"></i> Force Recompute Month
                    </button>
                </div>
            </div>

            <div id="monthly-run-result" class="ll-result-banner" style="display:none;"></div>

            <div id="monthly-preview-panel" class="ll-preview-panel" style="display:none;">
                <div class="ll-preview-header">
                    <div>
                        <strong id="monthly-preview-title" class="ll-preview-title"></strong>
                        <div id="monthly-preview-summary" class="ll-preview-summary"></div>
                    </div>
                    <div class="ll-preview-actions">
                        <button type="button" id="monthly-preview-cancel-btn" class="hris-btn-secondary">Cancel</button>
                        <button type="button" id="monthly-preview-apply-btn" class="hris-btn-primary">
                            <i class="fas fa-check fa-fw"></i> Apply
                        </button>
                    </div>
                </div>
                <div class="ll-preview-scroll">
                    {{-- Each preview table sits in its own wrapper div, and that div is what
                         gets shown/hidden -- toggling the raw <table> alone leaves the
                         DataTables-generated search box / pagination bar (siblings of the
                         table inside .dataTables_wrapper, not descendants of it) visible even
                         when the table itself is hidden. --}}
                    <div id="monthly-run-preview-wrap" style="display:none;">
                        <table id="monthly-run-preview-table" class="hris-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>EmpNo</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th class="text-right">WOP Days</th>
                                    <th class="text-right">Computed VL</th>
                                    <th class="text-right">Computed SL</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div id="monthly-force-preview-wrap" style="display:none;">
                        <table id="monthly-force-preview-table" class="hris-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>EmpNo</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th class="text-right">Old VL</th>
                                    <th class="text-right">Old SL</th>
                                    <th class="text-right">New VL</th>
                                    <th class="text-right">New SL</th>
                                    <th class="text-right">&Delta; VL</th>
                                    <th class="text-right">&Delta; SL</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="hris-table-card">
                <div class="hris-table-wrapper">
                    <table id="monthly-credits-table" class="hris-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>EmpNo</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Month</th>
                                <th class="text-right">WOP Days</th>
                                <th class="text-right">Computed VL</th>
                                <th class="text-right">Computed SL</th>
                                <th>Processed At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── Tab 3: AWOL Monitor ── --}}
        <div id="tab-awol" class="ledger-tab-panel" style="display:none;">
            <div class="lm-alert-banner lm-alert-banner--info">
                <i class="fas fa-circle-info fa-fw"></i>
                <span>
                    Employees currently accumulating unauthorized absence (no attendance, and nothing on file to cover it - no leave,
                    excuse, locator, or ETA). Per CSC rules, 30 continuous working days of AWOL is grounds for separation without
                    prior notice; under 30 days, a Return-to-Work Order should be served first. Only employees with a current streak
                    of 5+ workdays are shown.
                </span>
            </div>

            <div class="hris-table-card">
                <div class="hris-table-wrapper">
                    <table id="awol-monitor-table" class="hris-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>EmpNo</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th class="text-right">Current Streak</th>
                                <th>Streak Started On</th>
                                <th class="text-right">Episodes This Semester</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</section>

@endsection

@section('page_scripts_after')
<script>
$(function () {
    // ── Tab switching ──────────────────────────────────────────────────────────
    $('.ledger-tab-btn').on('click', function () {
        var target = $(this).data('tab');
        $('.ledger-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.ledger-tab-panel').hide();
        $('#' + target).show();
    });

    // ── Ledger History table ───────────────────────────────────────────────────
    var LEDGER_TYPE_BADGES = {
        CREDIT_EARNED:      { cls: 'credit',     label: 'Credit Earned' },
        CREDIT_EARNED_WOP:  { cls: 'credit',     label: 'Credit (WOP)' },
        CREDIT_CORRECTION:  { cls: 'correction', label: 'Correction' },
        LEAVE_USED:         { cls: 'debit',      label: 'Leave Used' },
        LEAVE_CANCELLED:    { cls: 'cancelled',  label: 'Cancelled' },
        MANUAL_ADJUSTMENT:  { cls: 'adjustment', label: 'Manual Adj.' },
        OPENING_BALANCE:    { cls: 'opening',    label: 'Opening Bal.' },
        MONETIZED:          { cls: 'neutral',    label: 'Monetized' },
        TERMINAL_LEAVE:     { cls: 'neutral',    label: 'Terminal Leave' },
        TRANSFER_IN:        { cls: 'credit',     label: 'Transfer In' },
        TRANSFER_OUT:       { cls: 'debit',      label: 'Transfer Out' },
        COMMUTED:           { cls: 'neutral',    label: 'Commuted' },
        LWOP_DEDUCTION:     { cls: 'debit',      label: 'LWOP Deduction' },
    };

    function amtCell(v, sign) {
        if (parseFloat(v) > 0) {
            return '<span class="ll-amt ll-amt--' + (sign === '+' ? 'pos' : 'neg') + '">' + sign + v + '</span>';
        }
        return '<span class="ll-amt ll-amt--zero">' + v + '</span>';
    }

    var historyTable = $('#ledger-history-table').DataTable({
        data: [],
        columns: [
            { data: 'date' },
            { data: 'type', render: function (v) {
                var b = LEDGER_TYPE_BADGES[v] || { cls: 'neutral', label: v };
                return '<span class="ll-badge ll-badge--' + b.cls + '">' + b.label + '</span>';
            }},
            { data: 'leave_type' },
            { data: 'credit_vl',        className: 'text-right', render: function (v) { return amtCell(v, '+'); }},
            { data: 'credit_sl',        className: 'text-right', render: function (v) { return amtCell(v, '+'); }},
            { data: 'debit_vl',         className: 'text-right', render: function (v) { return amtCell(v, '−'); }},
            { data: 'debit_sl',         className: 'text-right', render: function (v) { return amtCell(v, '−'); }},
            { data: 'vl_balance_after', className: 'text-right', render: function (v) { return '<span class="ll-balance-cell">'+v+'</span>'; }},
            { data: 'sl_balance_after', className: 'text-right', render: function (v) { return '<span class="ll-balance-cell">'+v+'</span>'; }},
            { data: 'abs_wop_days' },
            { data: 'remarks', defaultContent: '-' },
            { data: 'posted_by' },
        ],
        order: [[0, 'desc']],
        pageLength: 25,
        dom: '<"dt-top-bar"ip>rt<"dt-bottom-bar"lip>',
        language: { emptyTable: 'Select an employee and click Load.' },
    });

    // ── Employee autocomplete ──────────────────────────────────────────────────
    function buildSuggestions(query) {
        if (!query) return [];
        var q = query.toLowerCase();
        return EMPLOYEES.filter(function (e) {
            return e.name.toLowerCase().indexOf(q) !== -1 ||
                   (e.empno && e.empno.toLowerCase().indexOf(q) !== -1);
        }).slice(0, 15);
    }

    function renderSuggestions(items) {
        var $box = $('#ledger-emp-suggestions');
        $box.empty();
        if (!items.length) { $box.hide(); return; }
        items.forEach(function (e) {
            var label = e.name + (e.empno ? ' (' + e.empno + ')' : '') + (e.dept ? ' - ' + e.dept : '');
            $('<div class="ll-suggestion-item">').text(label)
                .on('mousedown', function (ev) {
                    ev.preventDefault();
                    selectEmployee(e);
                })
                .appendTo($box);
        });
        $box.show();
    }

    function selectEmployee(e) {
        $('#ledger-employee').val(e.id);
        $('#ledger-emp-input').val(e.name + (e.empno ? ' (' + e.empno + ')' : ''));
        $('#ledger-emp-suggestions').hide();
        updateDownloadBtn();
        loadHistory();
    }

    $('#ledger-emp-input')
        .on('input', function () {
            $('#ledger-employee').val('');
            updateDownloadBtn();
            renderSuggestions(buildSuggestions($(this).val().trim()));
        })
        .on('blur', function () {
            setTimeout(function () { $('#ledger-emp-suggestions').hide(); }, 150);
        })
        .on('keydown', function (ev) {
            if (ev.key === 'Escape') $('#ledger-emp-suggestions').hide();
        });

    // ── Download / Load ────────────────────────────────────────────────────────
    function updateDownloadBtn() {
        var userId = $('#ledger-employee').val();
        var year   = $('#ledger-year').val();
        var month  = $('#ledger-month').val();
        $('#ledger-download-btn').prop('disabled', !(userId && year && month));
    }

    function loadHistory() {
        var userId = $('#ledger-employee').val();
        if (!userId) {
            historyTable.clear().draw();
            $('#ledger-balance-summary').hide();
            return;
        }
        $.getJSON('{{ route('api.leave-ledger.history') }}', {
            user_id: userId,
            year:    $('#ledger-year').val(),
        }, function (res) {
            historyTable.clear().rows.add(res.data).draw();
            if (res.data.length > 0) {
                var last = res.data[0];
                $('#summary-vl').text(last.vl_balance_after);
                $('#summary-sl').text(last.sl_balance_after);
                $('#ledger-balance-summary').show();
            } else {
                $('#ledger-balance-summary').hide();
            }
        });
    }

    $('#ledger-load-btn').on('click', loadHistory);
    $('#ledger-year, #ledger-month').on('change', function () {
        updateDownloadBtn();
    });

    $('#ledger-download-btn').on('click', function () {
        var userId = $('#ledger-employee').val();
        var year   = $('#ledger-year').val();
        var month  = $('#ledger-month').val();
        if (!userId || !year || !month) return;
        startExport(
            EXPORT_JOBS_URL,
            { type: 'leave_card', params: { user_id: parseInt(userId), year: parseInt(year), month: parseInt(month) } },
            'Building leave card&hellip;'
        );
    });

    updateDownloadBtn();

    // ── Monthly Credits table ──────────────────────────────────────────────────
    var monthlyTable = $('#monthly-credits-table').DataTable({
        data: [],
        columns: [
            { data: 'emp_no' },
            { data: 'name' },
            { data: 'department' },
            { data: 'year' },
            { data: 'month' },
            { data: 'abs_wop_days', className: 'text-right' },
            { data: 'computed_vl',  className: 'text-right', render: function (v) { return v !== '-' ? '<span class="ll-amt ll-amt--pos">'+v+'</span>' : '-'; }},
            { data: 'computed_sl',  className: 'text-right', render: function (v) { return v !== '-' ? '<span class="ll-amt ll-amt--pos">'+v+'</span>' : '-'; }},
            { data: 'processed_at' },
            { data: null, orderable: false, render: function (row) {
                var statusHtml = row.stale
                    ? '<span class="ll-badge ll-badge--stale"><i class="fas fa-triangle-exclamation"></i> Stale</span>'
                    : '<span class="ll-badge ll-badge--ok"><i class="fas fa-check"></i> OK</span>';
                if (row.processed_at === '-') { return statusHtml; }
                return statusHtml + ' ' +
                    '<button type="button" class="hris-btn-secondary monthly-recompute-btn" ' +
                    'data-user-id="' + row.user_id + '" data-year="' + row.year + '" data-month="' + row.month_number + '" ' +
                    'style="padding:0.2rem 0.6rem;font-size:0.78rem;margin-left:0.4rem;">Recompute</button>';
            }},
        ],
        order: [[3, 'desc'], [4, 'desc'], [1, 'asc']],
        pageLength: 25,
        dom: '<"dt-top-bar"ip>rt<"dt-bottom-bar"lip>',
        language: { emptyTable: 'No monthly credit records found for the selected period.' },
    });

    function loadMonthly() {
        $.getJSON('{{ route('api.leave-ledger.monthly') }}', {
            year:  $('#monthly-year').val(),
            month: $('#monthly-month').val(),
        }, function (res) {
            monthlyTable.clear().rows.add(res.data).draw();
        });
    }

    $('#monthly-load-btn').on('click', loadMonthly);

    // Auto-load monthly on first tab switch to it
    var monthlyLoaded = false;
    $('[data-tab="tab-monthly"]').on('click', function () {
        if (!monthlyLoaded) { loadMonthly(); monthlyLoaded = true; }
    });

    // ── Run Monthly Credits ────────────────────────────────────────────────────
    var CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function showRunResult(message, isError) {
        $('#monthly-run-result')
            .removeClass('ll-result-banner--success ll-result-banner--error')
            .addClass(isError ? 'll-result-banner--error' : 'll-result-banner--success')
            .show()
            .text(message);
    }

    // ── Preview tables for Run Monthly Credits / Force Recompute Month ──────────
    var runPreviewTable = $('#monthly-run-preview-table').DataTable({
        data: [],
        columns: [
            { data: 'emp_no' },
            { data: 'name' },
            { data: 'department' },
            { data: 'abs_wop_days', className: 'text-right', defaultContent: '-' },
            { data: 'computed_vl',  className: 'text-right', defaultContent: '-' },
            { data: 'computed_sl',  className: 'text-right', defaultContent: '-' },
            { data: null, render: function (row) {
                if (row.status === 'error') { return '<span class="ll-badge ll-badge--debit"><i class="fas fa-circle-exclamation"></i> Error: ' + row.message + '</span>'; }
                return '<span class="ll-badge ll-badge--credit"><i class="fas fa-circle-check"></i> ' + (row.transaction_type === 'CREDIT_EARNED_WOP' ? 'Credit (WOP)' : 'Credit Earned') + '</span>';
            }},
        ],
        order: [[1, 'asc']],
        pageLength: 25,
        dom: '<"dt-top-bar"ip>rt<"dt-bottom-bar"lip>',
        language: { emptyTable: 'Nothing to preview.' },
    });

    var forcePreviewTable = $('#monthly-force-preview-table').DataTable({
        data: [],
        columns: [
            { data: 'emp_no' },
            { data: 'name' },
            { data: 'department' },
            { data: 'old_vl', className: 'text-right' },
            { data: 'old_sl', className: 'text-right' },
            { data: 'new_vl', className: 'text-right' },
            { data: 'new_sl', className: 'text-right' },
            { data: 'delta_vl', className: 'text-right', render: function (v, t, row) {
                return row.changed ? '<span class="ll-amt ll-amt--delta">'+v+'</span>' : '<span class="ll-amt ll-amt--zero">'+v+'</span>';
            }},
            { data: 'delta_sl', className: 'text-right', render: function (v, t, row) {
                return row.changed ? '<span class="ll-amt ll-amt--delta">'+v+'</span>' : '<span class="ll-amt ll-amt--zero">'+v+'</span>';
            }},
            { data: 'changed', render: {
                display: function (v) { return v ? '<span class="ll-badge ll-badge--warning"><i class="fas fa-rotate"></i> Will change</span>' : '<span class="ll-badge ll-badge--ok">No change</span>'; },
                sort: function (v) { return v ? 1 : 0; },
            }},
        ],
        order: [[9, 'desc'], [1, 'asc']],
        pageLength: 25,
        dom: '<"dt-top-bar"ip>rt<"dt-bottom-bar"lip>',
        language: { emptyTable: 'Nothing to preview.' },
    });

    var previewMode = null, previewYear = null, previewMonth = null;

    function resetPreviewPanel() {
        previewMode = null;
        $('#monthly-preview-panel').hide();
        $('#monthly-run-preview-wrap, #monthly-force-preview-wrap').hide();
        runPreviewTable.clear().draw();
        forcePreviewTable.clear().draw();
        $('#monthly-run-btn, #monthly-force-recompute-btn, #monthly-load-btn').prop('disabled', false);
    }

    function showPreviewPanel(mode, title, summaryText) {
        previewMode = mode;
        $('#monthly-preview-title').text(title);
        $('#monthly-preview-summary').text(summaryText);
        $('#monthly-run-preview-wrap').toggle(mode === 'run');
        $('#monthly-force-preview-wrap').toggle(mode === 'force');
        $('#monthly-preview-panel').show();
        $('#monthly-run-btn, #monthly-force-recompute-btn, #monthly-load-btn').prop('disabled', true);
        // The active table was initialized while its wrapper was display:none, so DataTables
        // never got a real width to size columns against -- recalculate now that it's visible.
        (mode === 'run' ? runPreviewTable : forcePreviewTable).columns.adjust().draw(false);
    }

    $('#monthly-run-btn').on('click', function () {
        var year = $('#monthly-year').val();
        var month = $('#monthly-month').val();

        if (!month) {
            showRunResult('Select a specific month before running (not "All months").', true);
            return;
        }

        var $btn = $(this).prop('disabled', true);
        var originalHtml = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-spin fa-fw"></i> Loading preview…');
        $('#monthly-run-result').hide();

        fetch('{{ route('leave-manager.run-monthly-credits.preview') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ year: parseInt(year), month: parseInt(month) }),
        })
            .then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            })
            .then(function (result) {
                $btn.prop('disabled', false).html(originalHtml);
                if (!result.ok) {
                    showRunResult(result.data.message || 'Failed to build preview.', true);
                    return;
                }
                previewYear = year;
                previewMonth = month;
                var s = result.data.summary;
                runPreviewTable.clear().rows.add(result.data.rows).draw();
                showPreviewPanel('run',
                    'Preview - Run Monthly Credits for ' + $('#monthly-month option:selected').text() + ' ' + year,
                    'Would process: ' + s.would_process + ' · Already processed (skipped): ' + s.would_skip + ' · Would fail: ' + s.would_fail);
            })
            .catch(function () {
                $btn.prop('disabled', false).html(originalHtml);
                showRunResult('Network error while building preview.', true);
            });
    });

    // ── Force-recompute every already-processed employee for the selected month ──
    $('#monthly-force-recompute-btn').on('click', function () {
        var year = $('#monthly-year').val();
        var month = $('#monthly-month').val();
        var $triggerBtn = $(this);

        if (!month) {
            showRunResult('Select a specific month before recomputing (not "All months").', true);
            return;
        }

        Swal.fire({
            icon: 'question',
            title: 'Force recompute this month?',
            html: 'This scans <strong>every already-processed employee</strong> for the selected month, even ones ' +
                  'not flagged Stale, and builds a preview of correction entries for anyone whose figure changes ' +
                  '(e.g. after a calculation fix).<br><br>Nothing is posted yet — you\'ll review the preview and ' +
                  'confirm again before anything is applied.',
            showCancelButton: true,
            confirmButtonText: 'Build Preview',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ea580c',
            cancelButtonColor: '#64748b',
            reverseButtons: true,
            focusCancel: true,
        }).then(function (confirmResult) {
            if (!confirmResult.isConfirmed) return;

            var $btn = $triggerBtn.prop('disabled', true);
            var originalHtml = $btn.html();
            $btn.html('<i class="fas fa-spinner fa-spin fa-fw"></i> Loading preview…');
            $('#monthly-run-result').hide();

            fetch('{{ route('leave-manager.force-recompute-month.preview') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ year: parseInt(year), month: parseInt(month) }),
            })
                .then(function (res) {
                    return res.json().then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (result) {
                    $btn.prop('disabled', false).html(originalHtml);
                    if (!result.ok) {
                        showRunResult(result.data.message || 'Failed to build preview.', true);
                        return;
                    }
                    previewYear = year;
                    previewMonth = month;
                    var s = result.data.summary;
                    forcePreviewTable.clear().rows.add(result.data.rows).draw();
                    showPreviewPanel('force',
                        'Preview - Force Recompute for ' + $('#monthly-month option:selected').text() + ' ' + year,
                        'Will change: ' + s.would_change + ' · No change: ' + s.would_noop + ' · Would fail: ' + s.would_fail);
                })
                .catch(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                    showRunResult('Network error while building preview.', true);
                });
        });
    });

    // ── Apply / Cancel the currently open preview ────────────────────────────────
    $('#monthly-preview-apply-btn').on('click', function () {
        if (!previewMode) return;
        var mode = previewMode;
        var $triggerBtn = $(this);

        var confirmHtml = mode === 'run'
            ? 'This posts real leave credits to the ledger for every employee shown in the preview above.'
            : 'This posts correction entries for every changed employee shown above. Employees marked ' +
              '<strong>"No change"</strong> just get their processed date refreshed, with no ledger entry.';

        Swal.fire({
            icon: 'warning',
            title: mode === 'run' ? 'Post these leave credits?' : 'Post correction entries?',
            html: confirmHtml,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check fa-fw"></i> Apply',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ea580c',
            cancelButtonColor: '#64748b',
            reverseButtons: true,
            focusCancel: true,
        }).then(function (confirmResult) {
            if (!confirmResult.isConfirmed) return;

            var routeUrl = mode === 'run'
                ? '{{ route('leave-manager.run-monthly-credits') }}'
                : '{{ route('leave-manager.force-recompute-month') }}';

            var $btn = $triggerBtn.prop('disabled', true);
            var originalHtml = $btn.html();
            $btn.html('<i class="fas fa-spinner fa-spin fa-fw"></i> Applying…');
            $('#monthly-preview-cancel-btn').prop('disabled', true);

            fetch(routeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ year: parseInt(previewYear), month: parseInt(previewMonth) }),
            })
                .then(function (res) {
                    return res.json().then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (result) {
                    if (!result.ok) {
                        showRunResult(result.data.message || 'Failed to apply.', true);
                        return;
                    }
                    var d = result.data;
                    if (mode === 'run') {
                        showRunResult('Processed: ' + d.processed + ', Skipped: ' + d.skipped + ', Failed: ' + d.failed, d.failed > 0);
                        $('#monthly-credit-reminder').hide();
                    } else {
                        showRunResult('Recomputed: ' + d.recomputed + ', Changed: ' + d.changed + ', Failed: ' + d.failed, d.failed > 0);
                    }
                    resetPreviewPanel();
                    loadMonthly();
                    if (d.failed === 0) {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Applied', showConfirmButton: false, timer: 1600 });
                    }
                })
                .catch(function () {
                    showRunResult('Network error while applying.', true);
                })
                .finally(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                    $('#monthly-preview-cancel-btn').prop('disabled', false);
                });
        });
    });

    $('#monthly-preview-cancel-btn').on('click', resetPreviewPanel);

    // ── Recompute a single stale employee-month ────────────────────────────────
    $('#monthly-credits-table tbody').on('click', '.monthly-recompute-btn', function () {
        var $btn = $(this);
        var userId = $btn.data('user-id');
        var year = $btn.data('year');
        var month = $btn.data('month');

        Swal.fire({
            icon: 'question',
            title: 'Recompute this employee-month?',
            text: 'Only the difference from the previously-posted amount will be added to the ledger, not the full amount again.',
            showCancelButton: true,
            confirmButtonText: 'Recompute',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ea580c',
            cancelButtonColor: '#64748b',
            reverseButtons: true,
            focusCancel: true,
        }).then(function (confirmResult) {
            if (!confirmResult.isConfirmed) return;

            $btn.prop('disabled', true).text('Working…');

            fetch('{{ route('leave-manager.recompute-employee-month') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ user_id: userId, year: year, month: month }),
            })
                .then(function (res) {
                    return res.json().then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (result) {
                    if (!result.ok) {
                        Swal.fire({ icon: 'error', title: 'Recompute failed', text: result.data.message || 'Something went wrong.' });
                        $btn.prop('disabled', false).text('Recompute');
                        return;
                    }
                    var d = result.data;
                    if (d.changed) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Corrected',
                            html: 'VL change: <strong>' + d.delta_vl + '</strong><br>SL change: <strong>' + d.delta_sl + '</strong>',
                            confirmButtonColor: '#ea580c',
                        });
                    } else {
                        Swal.fire({ icon: 'info', title: 'No change needed', text: 'Figures were already correct.', confirmButtonColor: '#ea580c' });
                    }
                    loadMonthly();
                })
                .catch(function () {
                    Swal.fire({ icon: 'error', title: 'Network error', text: 'Network error while recomputing.' });
                    $btn.prop('disabled', false).text('Recompute');
                });
        });
    });

    // ── AWOL Monitor table ──────────────────────────────────────────────────────
    var AWOL_BADGES = {
        watch:    { cls: 'watch',    label: 'Watch' },
        warning:  { cls: 'warning',  label: 'Warning' },
        urgent:   { cls: 'urgent',   label: 'Urgent' },
        critical: { cls: 'critical', label: 'Critical' },
    };

    var awolTable = $('#awol-monitor-table').DataTable({
        data: [],
        columns: [
            { data: 'emp_no' },
            { data: 'name' },
            { data: 'department' },
            { data: 'streak', className: 'text-right' },
            { data: 'streak_started_on', defaultContent: '-' },
            { data: 'episodes_this_semester', className: 'text-right' },
            { data: 'status', render: function (v) {
                var b = AWOL_BADGES[v] || { cls: 'neutral', label: v };
                return '<span class="ll-badge ll-badge--' + b.cls + '">' + b.label + '</span>';
            }},
        ],
        order: [[3, 'desc']],
        pageLength: 25,
        dom: '<"dt-top-bar"ip>rt<"dt-bottom-bar"lip>',
        language: { emptyTable: 'No employees currently accumulating unauthorized absence.' },
    });

    function loadAwolMonitor() {
        $.getJSON('{{ route('api.leave-ledger.awol-monitor') }}', {}, function (res) {
            awolTable.clear().rows.add(res.data).draw();
        });
    }

    var awolLoaded = false;
    $('[data-tab="tab-awol"]').on('click', function () {
        if (!awolLoaded) { loadAwolMonitor(); awolLoaded = true; }
    });
});
</script>
@endsection
