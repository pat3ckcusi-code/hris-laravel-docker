@extends('dashboards.layout', [
    'title'    => 'Leave Ledger',
    'subtitle' => 'Audit trail of leave credits and deductions per employee',
])

@section('content')

<section class="card">
    <header>
        <h2>Leave Ledger</h2>
    </header>

    <div class="card-body">

        {{-- Tabs --}}
        <div class="ledger-tabs" style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1.5rem;">
            <button type="button" class="ledger-tab-btn active" data-tab="tab-history"
                style="padding:0.55rem 1.25rem;border:none;background:none;cursor:pointer;font-size:0.9rem;font-weight:600;color:#3b82f6;border-bottom:2px solid #3b82f6;margin-bottom:-2px;">
                Ledger History
            </button>
            <button type="button" class="ledger-tab-btn" data-tab="tab-monthly"
                style="padding:0.55rem 1.25rem;border:none;background:none;cursor:pointer;font-size:0.9rem;font-weight:600;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-2px;">
                Monthly Credits
            </button>
            <button type="button" class="ledger-tab-btn" data-tab="tab-awol"
                style="padding:0.55rem 1.25rem;border:none;background:none;cursor:pointer;font-size:0.9rem;font-weight:600;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-2px;">
                AWOL Monitor
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

            <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;margin-bottom:1rem;">
                <div style="position:relative;">
                    <label class="muted" style="display:block;font-size:0.78rem;margin-bottom:3px;">Employee</label>
                    <input type="text" id="ledger-emp-input" placeholder="Type name or EmpNo…" autocomplete="off"
                        style="min-width:280px;padding:0.45rem 0.7rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.88rem;">
                    <div id="ledger-emp-suggestions"
                        style="display:none;position:absolute;top:100%;left:0;min-width:320px;max-height:220px;overflow-y:auto;
                               background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);
                               z-index:9999;margin-top:2px;"></div>
                </div>
                <div>
                    <label class="muted" style="display:block;font-size:0.78rem;margin-bottom:3px;">Year</label>
                    <select id="ledger-year" style="padding:0.45rem 0.7rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.88rem;">
                        <option value="">All years</option>
                        @foreach($years as $y)
                            <option value="{{ $y }}" @selected($y == $currentYear)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="muted" style="display:block;font-size:0.78rem;margin-bottom:3px;">Month</label>
                    <select id="ledger-month" style="padding:0.45rem 0.7rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.88rem;">
                        <option value="">All months</option>
                        @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                            <option value="{{ $mi + 1 }}" @selected($mi + 1 == now()->month)>{{ $mn }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" id="ledger-load-btn" class="hris-btn hris-btn-primary" style="padding:0.45rem 1.1rem;">Load</button>
                <button type="button" id="ledger-download-btn" class="hris-btn hris-btn-secondary" disabled
                    style="padding:0.45rem 1.1rem;" title="Select employee and month to enable">
                    <i class="fas fa-file-excel fa-fw"></i> Download Leave Card
                </button>
            </div>

            <div id="ledger-balance-summary" style="display:none;padding:0.6rem 1rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;margin-bottom:1rem;font-size:0.88rem;color:#0369a1;">
                Current balance - <strong>VL: <span id="summary-vl">-</span></strong> &nbsp;|&nbsp; <strong>SL: <span id="summary-sl">-</span></strong>
            </div>

            <div class="table-responsive">
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

        {{-- ── Tab 2: Monthly Credits ── --}}
        <div id="tab-monthly" class="ledger-tab-panel" style="display:none;">
            @unless($lastMonthProcessed)
            <div id="monthly-credit-reminder" style="padding:0.6rem 1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;margin-bottom:1rem;font-size:0.86rem;color:#92400e;">
                <i class="fas fa-triangle-exclamation fa-fw"></i>
                {{ $lastMonthLabel }} leave credits have not been processed yet. Select it below and click Run Monthly Credits.
            </div>
            @endunless

            <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;margin-bottom:1rem;">
                <div>
                    <label class="muted" style="display:block;font-size:0.78rem;margin-bottom:3px;">Year</label>
                    <select id="monthly-year" style="padding:0.45rem 0.7rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.88rem;">
                        @foreach($years as $y)
                            <option value="{{ $y }}" @selected($y == $currentYear)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="muted" style="display:block;font-size:0.78rem;margin-bottom:3px;">Month</label>
                    <select id="monthly-month" style="padding:0.45rem 0.7rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.88rem;">
                        <option value="">All months</option>
                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                            <option value="{{ $mi + 1 }}" @selected($mi + 1 == $lastMonthMonth)>{{ $mn }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" id="monthly-load-btn" class="hris-btn hris-btn-secondary" style="padding:0.45rem 1.1rem;">Load</button>
                <button type="button" id="monthly-run-btn" class="hris-btn hris-btn-primary" style="padding:0.45rem 1.1rem;">
                    <i class="fas fa-play fa-fw"></i> Run Monthly Credits
                </button>
                <button type="button" id="monthly-force-recompute-btn" class="hris-btn hris-btn-secondary" style="padding:0.45rem 1.1rem;">
                    <i class="fas fa-rotate fa-fw"></i> Force Recompute Month
                </button>
            </div>

            <div id="monthly-run-result" style="display:none;padding:0.55rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:0.86rem;"></div>

            <div class="table-responsive">
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

        {{-- ── Tab 3: AWOL Monitor ── --}}
        <div id="tab-awol" class="ledger-tab-panel" style="display:none;">
            <p class="muted" style="font-size:0.82rem;margin-bottom:0.75rem;">
                Employees currently accumulating unauthorized absence (no attendance, and nothing on file to cover it - no leave,
                excuse, locator, or ETA). Per CSC rules, 30 continuous working days of AWOL is grounds for separation without
                prior notice; under 30 days, a Return-to-Work Order should be served first. Only employees with a current streak
                of 5+ workdays are shown.
            </p>

            <div class="table-responsive">
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
</section>

@endsection

@section('page_scripts_after')
<script>
$(function () {
    // ── Tab switching ──────────────────────────────────────────────────────────
    $('.ledger-tab-btn').on('click', function () {
        var target = $(this).data('tab');
        $('.ledger-tab-btn').each(function () {
            var active = $(this).data('tab') === target;
            $(this).css({
                color:              active ? '#3b82f6' : '#64748b',
                'border-bottom-color': active ? '#3b82f6' : 'transparent',
                'font-weight':      active ? '600' : '500',
            });
        });
        $('.ledger-tab-panel').hide();
        $('#' + target).show();
    });

    // ── Ledger History table ───────────────────────────────────────────────────
    var historyTable = $('#ledger-history-table').DataTable({
        data: [],
        columns: [
            { data: 'date' },
            { data: 'type', render: function (v) {
                var map = {
                    CREDIT_EARNED: '<span style="color:#16a34a;font-weight:600;">Credit Earned</span>',
                    CREDIT_EARNED_WOP: '<span style="color:#16a34a;font-weight:600;">Credit (WOP)</span>',
                    CREDIT_CORRECTION: '<span style="color:#ea580c;font-weight:600;">Correction</span>',
                    LEAVE_USED: '<span style="color:#dc2626;font-weight:600;">Leave Used</span>',
                    LEAVE_CANCELLED: '<span style="color:#d97706;font-weight:600;">Cancelled</span>',
                    MANUAL_ADJUSTMENT: '<span style="color:#7c3aed;font-weight:600;">Manual Adj.</span>',
                    OPENING_BALANCE: '<span style="color:#0284c7;font-weight:600;">Opening Bal.</span>',
                    MONETIZED: '<span style="color:#0891b2;">Monetized</span>',
                    TERMINAL_LEAVE: '<span style="color:#475569;">Terminal Leave</span>',
                    TRANSFER_IN: '<span style="color:#16a34a;">Transfer In</span>',
                    TRANSFER_OUT: '<span style="color:#dc2626;">Transfer Out</span>',
                    COMMUTED: '<span style="color:#475569;">Commuted</span>',
                    LWOP_DEDUCTION: '<span style="color:#dc2626;">LWOP Deduction</span>',
                };
                return map[v] || v;
            }},
            { data: 'leave_type' },
            { data: 'credit_vl',        className: 'text-right', render: function (v) { return parseFloat(v) > 0 ? '<span style="color:#16a34a;">+'+v+'</span>' : '<span style="color:#cbd5e1;">'+v+'</span>'; }},
            { data: 'credit_sl',        className: 'text-right', render: function (v) { return parseFloat(v) > 0 ? '<span style="color:#16a34a;">+'+v+'</span>' : '<span style="color:#cbd5e1;">'+v+'</span>'; }},
            { data: 'debit_vl',         className: 'text-right', render: function (v) { return parseFloat(v) > 0 ? '<span style="color:#dc2626;">−'+v+'</span>' : '<span style="color:#cbd5e1;">'+v+'</span>'; }},
            { data: 'debit_sl',         className: 'text-right', render: function (v) { return parseFloat(v) > 0 ? '<span style="color:#dc2626;">−'+v+'</span>' : '<span style="color:#cbd5e1;">'+v+'</span>'; }},
            { data: 'vl_balance_after', className: 'text-right', render: function (v) { return '<strong>'+v+'</strong>'; }},
            { data: 'sl_balance_after', className: 'text-right', render: function (v) { return '<strong>'+v+'</strong>'; }},
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
            $('<div>').text(label)
                .css({ padding: '0.45rem 0.75rem', cursor: 'pointer', fontSize: '0.86rem', borderBottom: '1px solid #f1f5f9' })
                .on('mouseenter', function () { $(this).css('background', '#f0f9ff'); })
                .on('mouseleave', function () { $(this).css('background', ''); })
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
            { data: 'computed_vl',  className: 'text-right', render: function (v) { return v !== '-' ? '<span style="color:#16a34a;font-weight:600;">'+v+'</span>' : '-'; }},
            { data: 'computed_sl',  className: 'text-right', render: function (v) { return v !== '-' ? '<span style="color:#16a34a;font-weight:600;">'+v+'</span>' : '-'; }},
            { data: 'processed_at' },
            { data: null, orderable: false, render: function (row) {
                var statusHtml = row.stale
                    ? '<span style="color:#b45309;font-weight:600;">&#9888; Stale</span>'
                    : '<span style="color:#16a34a;">OK</span>';
                if (row.processed_at === '-') { return statusHtml; }
                return statusHtml + ' ' +
                    '<button type="button" class="hris-btn hris-btn-secondary monthly-recompute-btn" ' +
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
            .css({
                display: 'block',
                background: isError ? '#fef2f2' : '#f0fdf4',
                border: '1px solid ' + (isError ? '#fecaca' : '#bbf7d0'),
                color: isError ? '#b91c1c' : '#15803d',
            })
            .text(message);
    }

    $('#monthly-run-btn').on('click', function () {
        var year = $('#monthly-year').val();
        var month = $('#monthly-month').val();

        if (!month) {
            showRunResult('Select a specific month before running (not "All months").', true);
            return;
        }

        if (!window.confirm(
            'This will post real leave credits for every eligible employee for the selected month. ' +
            'Already-processed employees are skipped, so it is safe to re-run. Continue?'
        )) {
            return;
        }

        var $btn = $(this).prop('disabled', true);
        var originalHtml = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-spin fa-fw"></i> Running…');
        $('#monthly-run-result').hide();

        fetch('{{ route('leave-manager.run-monthly-credits') }}', {
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
                if (!result.ok) {
                    showRunResult(result.data.message || 'Failed to process monthly credits.', true);
                    return;
                }
                var d = result.data;
                showRunResult('Processed: ' + d.processed + ', Skipped: ' + d.skipped + ', Failed: ' + d.failed, d.failed > 0);
                $('#monthly-credit-reminder').hide();
                loadMonthly();
            })
            .catch(function () {
                showRunResult('Network error while processing monthly credits.', true);
            })
            .finally(function () {
                $btn.prop('disabled', false).html(originalHtml);
            });
    });

    // ── Force-recompute every already-processed employee for the selected month ──
    $('#monthly-force-recompute-btn').on('click', function () {
        var year = $('#monthly-year').val();
        var month = $('#monthly-month').val();

        if (!month) {
            showRunResult('Select a specific month before recomputing (not "All months").', true);
            return;
        }

        if (!window.confirm(
            'This will recompute every already-processed employee for the selected month, even ones ' +
            'not flagged Stale, and post a correction entry for anyone whose figure changes (e.g. after ' +
            'a calculation fix). Employees with no change get no ledger entry. Continue?'
        )) {
            return;
        }

        var $btn = $(this).prop('disabled', true);
        var originalHtml = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-spin fa-fw"></i> Recomputing…');
        $('#monthly-run-result').hide();

        fetch('{{ route('leave-manager.force-recompute-month') }}', {
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
                if (!result.ok) {
                    showRunResult(result.data.message || 'Failed to force-recompute monthly credits.', true);
                    return;
                }
                var d = result.data;
                showRunResult('Recomputed: ' + d.recomputed + ', Changed: ' + d.changed + ', Failed: ' + d.failed, d.failed > 0);
                loadMonthly();
            })
            .catch(function () {
                showRunResult('Network error while force-recomputing monthly credits.', true);
            })
            .finally(function () {
                $btn.prop('disabled', false).html(originalHtml);
            });
    });

    // ── Recompute a single stale employee-month ────────────────────────────────
    $('#monthly-credits-table tbody').on('click', '.monthly-recompute-btn', function () {
        var $btn = $(this);
        var userId = $btn.data('user-id');
        var year = $btn.data('year');
        var month = $btn.data('month');

        if (!window.confirm(
            'Recompute this employee\'s credit for this month? Only the difference from the ' +
            'previously-posted amount will be added to the ledger, not the full amount again.'
        )) {
            return;
        }

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
                    window.alert(result.data.message || 'Recompute failed.');
                    $btn.prop('disabled', false).text('Recompute');
                    return;
                }
                var d = result.data;
                window.alert(d.changed
                    ? 'Corrected. VL change: ' + d.delta_vl + ', SL change: ' + d.delta_sl
                    : 'No change needed - figures were already correct.');
                loadMonthly();
            })
            .catch(function () {
                window.alert('Network error while recomputing.');
                $btn.prop('disabled', false).text('Recompute');
            });
    });

    // ── AWOL Monitor table ──────────────────────────────────────────────────────
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
                var map = {
                    watch: '<span style="color:#64748b;font-weight:600;">Watch</span>',
                    warning: '<span style="color:#d97706;font-weight:600;">Warning</span>',
                    urgent: '<span style="color:#ea580c;font-weight:600;">Urgent</span>',
                    critical: '<span style="color:#dc2626;font-weight:700;">Critical</span>',
                };
                return map[v] || v;
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
