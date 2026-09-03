@extends('dashboards.layout', [
    'title' => 'Workforce Calendar',
    'subtitle' => 'Who\'s away each day - Leave, ETA, Locator, Travel Order, and Office Order at a glance.',
])

@section('page_head')
<style>
/* ── Color roles — one categorical hue per absence source, fixed order ──
   Validated for CVD-safe adjacent separation (script: dataviz/validate_palette.js).
   Tinted badge backgrounds are derived from these via color-mix(), with a
   flat-color fallback rule first for browsers without color-mix() support. */
.wfc-page {
    --wfc-leave:        #2a78d6;
    --wfc-eta:          #1baf7a;
    --wfc-locator:      #eda100;
    --wfc-travel_order: #4a3aa7;
    --wfc-office_order: #e34948;
}

.wfc-stat-strip { display: flex; flex-wrap: wrap; gap: .85rem; margin-bottom: 1.25rem; }
.wfc-stat-card {
    flex: 1; min-width: 9.5rem;
    background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    padding: .9rem 1.1rem;
    display: flex; align-items: center; gap: .75rem;
}
.wfc-stat-icon {
    width: 2.35rem; height: 2.35rem; border-radius: .6rem; flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center; font-size: .95rem;
}
.wfc-stat-value { font-size: 1.3rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
.wfc-stat-label { font-size: .72rem; color: #64748b; margin-top: .1rem; }

.wfc-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
    padding: .9rem 1.25rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: .75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.wfc-toolbar-left { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.wfc-dept-select {
    padding: .45rem .75rem;
    border: 1px solid #cbd5e1;
    border-radius: .4rem;
    font-size: .83rem;
    background: #f8fafc;
    color: #0f172a;
    min-width: 13rem;
}
.wfc-dept-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .85rem; font-weight: 600; color: #0f172a;
}
.wfc-nav-btn {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .42rem .85rem; border: 1px solid #cbd5e1; border-radius: .4rem;
    font-size: .82rem; font-weight: 500; background: #fff; color: #374151;
    text-decoration: none; cursor: pointer; transition: background .15s, border-color .15s;
}
.wfc-nav-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
.wfc-month-label { font-size: 1rem; font-weight: 700; color: #0f172a; white-space: nowrap; padding: 0 .35rem; min-width: 8.5rem; text-align: center; }

.wfc-legend { display: flex; flex-wrap: wrap; gap: 1.1rem; margin-bottom: 1rem; padding: 0 .1rem; }
.wfc-legend-item { display: flex; align-items: center; gap: .45rem; font-size: .78rem; color: #475569; font-weight: 500; }
.wfc-dot { width: .62rem; height: .62rem; border-radius: 50%; flex: 0 0 auto; }

.wfc-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: .9rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    padding: 1rem;
}

.wfc-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: .55rem;
}
.wfc-weekday {
    text-align: center; font-size: .72rem; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: .05em; padding-bottom: .4rem;
}
.wfc-day {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: .65rem;
    min-height: 6.75rem;
    padding: .55rem;
    display: flex;
    flex-direction: column;
    gap: .35rem;
    cursor: default;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
}
.wfc-day.is-weekend { background: #f8fafc; }
.wfc-day.has-data { cursor: pointer; }
.wfc-day.has-data:hover { border-color: #93c5fd; box-shadow: 0 4px 10px rgba(59,130,246,.14); transform: translateY(-1px); }
.wfc-day.is-blank { background: transparent; border: none; box-shadow: none; }
.wfc-day.is-today { border-color: #3b82f6; box-shadow: 0 0 0 1px #3b82f6 inset; }
.wfc-day-head { display: flex; align-items: center; justify-content: space-between; }
.wfc-day-num { font-size: .8rem; font-weight: 700; color: #0f172a; }
.wfc-today-pill {
    font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    background: #3b82f6; color: #fff; padding: .05rem .4rem; border-radius: 9999px;
}
.wfc-badges { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: auto; }
.wfc-badge {
    display: inline-flex; align-items: center; gap: .28rem;
    padding: .14rem .42rem; border-radius: 9999px; font-size: .68rem; font-weight: 700;
    line-height: 1.2;
}
.wfc-badge i { font-size: .6rem; }

/* Fallback flat pairs (pre-color-mix browsers), then the computed tint on top. */
.wfc-cat-leave        { background: #dbeafe; color: #1d4ed8; }
.wfc-cat-eta          { background: #d1fae5; color: #0f766e; }
.wfc-cat-locator      { background: #fef3c7; color: #92400e; }
.wfc-cat-travel_order { background: #ede9fe; color: #4a3aa7; }
.wfc-cat-office_order { background: #fee2e2; color: #b91c1c; }

.wfc-cat-leave        { background: color-mix(in srgb, var(--wfc-leave) 16%, white);        color: color-mix(in srgb, var(--wfc-leave) 75%, black); }
.wfc-cat-eta          { background: color-mix(in srgb, var(--wfc-eta) 18%, white);          color: color-mix(in srgb, var(--wfc-eta) 65%, black); }
.wfc-cat-locator      { background: color-mix(in srgb, var(--wfc-locator) 20%, white);      color: color-mix(in srgb, var(--wfc-locator) 60%, black); }
.wfc-cat-travel_order { background: color-mix(in srgb, var(--wfc-travel_order) 16%, white); color: color-mix(in srgb, var(--wfc-travel_order) 80%, black); }
.wfc-cat-office_order { background: color-mix(in srgb, var(--wfc-office_order) 16%, white); color: color-mix(in srgb, var(--wfc-office_order) 75%, black); }

.wfc-dot-leave        { background: var(--wfc-leave); }
.wfc-dot-eta          { background: var(--wfc-eta); }
.wfc-dot-locator      { background: var(--wfc-locator); }
.wfc-dot-travel_order { background: var(--wfc-travel_order); }
.wfc-dot-office_order { background: var(--wfc-office_order); }

.wfc-emp-group { margin-bottom: 1rem; }
.wfc-emp-group:last-child { margin-bottom: 0; }
.wfc-emp-group-title {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .75rem; font-weight: 700; margin-bottom: .4rem;
    padding: .2rem .6rem; border-radius: .4rem;
}
.wfc-emp-name { font-weight: 600; color: #0f172a; }

.wfc-order-link {
    background: none; border: none; padding: 0; margin: 0;
    font: inherit; color: #2563eb; cursor: pointer; text-decoration: underline;
    text-align: left;
}
.wfc-order-link:hover { color: #1d4ed8; }

.wfc-order-body { font-size: .85rem; color: #1f2937; }
.wfc-order-row { display: flex; gap: .75rem; margin-bottom: .6rem; }
.wfc-order-row-label { width: 8.5rem; flex: 0 0 auto; font-weight: 700; color: #475569; }
.wfc-order-row-value { flex: 1; white-space: pre-line; }
.wfc-order-section-title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #64748b; margin: 1rem 0 .5rem; }
.wfc-order-emp-list { list-style: none; margin: 0; padding: 0; }
.wfc-order-emp-list li { padding: .25rem 0; border-bottom: 1px solid #f1f5f9; }
.wfc-order-emp-list li:last-child { border-bottom: none; }
</style>
@endsection

@php
    $prevDate = $monthStart->copy()->subMonthNoOverflow();
    $nextDate = $monthStart->copy()->addMonthNoOverflow();
    $todayStr = \Illuminate\Support\Carbon::now()->toDateString();
    $leadingBlanks = $monthStart->copy()->startOfMonth()->dayOfWeek;
    $weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $categoryIcons = [
        'leave' => 'fa-calendar-check',
        'eta' => 'fa-plane-departure',
        'locator' => 'fa-map-marker-alt',
        'travel_order' => 'fa-route',
        'office_order' => 'fa-file-signature',
    ];
    $categoryTotals = array_fill_keys(array_keys($categoryLabels), 0);
    foreach ($calendarData as $dayData) {
        foreach ($dayData['counts'] as $type => $count) {
            $categoryTotals[$type] += $count;
        }
    }
@endphp

@section('content')

<div class="wfc-page">

<div class="wfc-stat-strip">
    @foreach ($categoryLabels as $type => $label)
        <div class="wfc-stat-card">
            <span class="wfc-stat-icon wfc-cat-{{ $type }}"><i class="fas {{ $categoryIcons[$type] }}"></i></span>
            <div>
                <div class="wfc-stat-value">{{ $categoryTotals[$type] }}</div>
                <div class="wfc-stat-label">{{ $label }} this month</div>
            </div>
        </div>
    @endforeach
</div>

<div class="wfc-toolbar">
    <div class="wfc-toolbar-left">
        @if ($departments->count() > 1)
            <form method="GET" action="{{ route('attendance.workforce-calendar.index') }}" class="wfc-toolbar-left">
                <select name="department_id" class="wfc-dept-select" onchange="this.form.submit()">
                    @foreach ($departments as $d)
                        <option value="{{ $d->Dept_id }}" @selected($departmentId === (int) $d->Dept_id)>{{ $d->Dept_name }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="month" value="{{ $month }}">
                <input type="hidden" name="year" value="{{ $year }}">
            </form>
        @elseif ($dept)
            <span class="wfc-dept-badge"><i class="fas fa-building" style="color:#94a3b8;"></i>{{ $dept->Dept_name }}</span>
        @endif
    </div>

    <div class="wfc-toolbar-left">
        <a class="wfc-nav-btn" href="{{ route('attendance.workforce-calendar.index', ['month' => $prevDate->month, 'year' => $prevDate->year, 'department_id' => $departmentId]) }}">
            <i class="fas fa-chevron-left"></i>
        </a>
        <span class="wfc-month-label">{{ $monthStart->format('F Y') }}</span>
        <a class="wfc-nav-btn" href="{{ route('attendance.workforce-calendar.index', ['month' => $nextDate->month, 'year' => $nextDate->year, 'department_id' => $departmentId]) }}">
            <i class="fas fa-chevron-right"></i>
        </a>
        <a class="wfc-nav-btn" href="{{ route('attendance.workforce-calendar.index', ['department_id' => $departmentId]) }}">Today</a>
    </div>
</div>

<div class="wfc-legend">
    @foreach ($categoryLabels as $type => $label)
        <span class="wfc-legend-item"><span class="wfc-dot wfc-dot-{{ $type }}"></span>{{ $label }}</span>
    @endforeach
</div>

@if (! $dept)
    <div class="hris-table-card">
        <div class="hris-empty-state">
            <div class="hris-empty-state-icon"><i class="fas fa-building"></i></div>
            <div class="hris-empty-state-title">No Department Available</div>
            <p class="hris-empty-state-text">No department is assigned to your account.</p>
        </div>
    </div>
@else
    <div class="wfc-card">
        <div class="wfc-grid">
            @foreach ($weekdayLabels as $wd)
                <div class="wfc-weekday">{{ $wd }}</div>
            @endforeach

            @for ($i = 0; $i < $leadingBlanks; $i++)
                <div class="wfc-day is-blank"></div>
            @endfor

            @foreach ($calendarData as $dateStr => $dayData)
                @php
                    $hasData = collect($dayData['counts'])->sum() > 0;
                    $carbonDate = \Illuminate\Support\Carbon::parse($dateStr);
                    $isWeekend = in_array($carbonDate->dayOfWeek, [0, 6], true);
                @endphp
                <div class="wfc-day {{ $hasData ? 'has-data' : '' }} {{ $dateStr === $todayStr ? 'is-today' : '' }} {{ $isWeekend ? 'is-weekend' : '' }}"
                     @if ($hasData)
                         data-date="{{ $dateStr }}"
                         data-employees='@json($dayData['employees'])'
                     @endif
                >
                    <div class="wfc-day-head">
                        <span class="wfc-day-num">{{ $carbonDate->day }}</span>
                        @if ($dateStr === $todayStr)
                            <span class="wfc-today-pill">Today</span>
                        @endif
                    </div>
                    @if ($hasData)
                        <div class="wfc-badges">
                            @foreach ($dayData['counts'] as $type => $count)
                                @if ($count > 0)
                                    <span class="wfc-badge wfc-cat-{{ $type }}"><i class="fas {{ $categoryIcons[$type] }}"></i>{{ $count }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

</div>

{{-- Day detail modal --}}
<div class="modal-overlay" id="wfc-day-modal">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="closeWfcDayModal()">&times;</button>
        <h3 id="wfc-day-modal-title"></h3>
        <div id="wfc-day-modal-body"></div>
    </div>
</div>

{{-- Office Order detail modal --}}
<div class="modal-overlay" id="wfc-office-order-modal">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="closeWfcOfficeOrderModal()">&times;</button>
        <h3>Office Order Details</h3>
        <div class="wfc-order-body" id="wfc-office-order-body"></div>
    </div>
</div>

{{-- Travel Order detail modal --}}
<div class="modal-overlay" id="wfc-travel-order-modal">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="closeWfcTravelOrderModal()">&times;</button>
        <h3>Travel Order Details</h3>
        <div class="wfc-order-body" id="wfc-travel-order-body"></div>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
var wfcCategoryLabels = @json($categoryLabels);

document.querySelectorAll('.wfc-day.has-data').forEach(function (cell) {
    cell.addEventListener('click', function () {
        var dateStr = this.dataset.date;
        var employees = JSON.parse(this.dataset.employees || '[]');

        var d = new Date(dateStr + 'T00:00:00');
        document.getElementById('wfc-day-modal-title').textContent = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

        var grouped = {};
        employees.forEach(function (e) {
            grouped[e.type] = grouped[e.type] || [];
            grouped[e.type].push(e);
        });

        var body = document.getElementById('wfc-day-modal-body');
        var html = '';
        Object.keys(wfcCategoryLabels).forEach(function (type) {
            if (!grouped[type]) { return; }
            html += '<div class="wfc-emp-group">';
            html += '<div class="wfc-emp-group-title wfc-cat-' + type + '">' + wfcCategoryLabels[type] + ' (' + grouped[type].length + ')</div>';
            grouped[type].forEach(function (e) {
                var valueHtml = e.label;
                if ((e.type === 'travel_order' || e.type === 'office_order') && e.order_id) {
                    var opener = e.type === 'travel_order' ? 'wfcOpenTravelOrder' : 'wfcOpenOfficeOrder';
                    valueHtml = '<button type="button" class="wfc-order-link" onclick="' + opener + '(' + e.order_id + ')">' + e.label + '</button>';
                }
                html += '<div class="detail-row"><span class="wfc-emp-name">' + e.name + '</span><span>' + valueHtml + '</span></div>';
            });
            html += '</div>';
        });

        body.innerHTML = html || '<p style="color:#94a3b8;">No employees this day.</p>';
        document.getElementById('wfc-day-modal').classList.add('active');
    });
});

function closeWfcDayModal() {
    document.getElementById('wfc-day-modal').classList.remove('active');
}

document.getElementById('wfc-day-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeWfcDayModal();
    }
});

function wfcEscapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
}

function wfcFormatDate(s) {
    if (!s) { return '-'; }
    var d = new Date(String(s).slice(0, 10) + 'T00:00:00');
    if (isNaN(d)) { return wfcEscapeHtml(s); }
    return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function wfcOrderRow(label, value) {
    return '<div class="wfc-order-row"><div class="wfc-order-row-label">' + wfcEscapeHtml(label) + '</div><div class="wfc-order-row-value">' + (value || '-') + '</div></div>';
}

async function wfcOpenOfficeOrder(id) {
    var overlay = document.getElementById('wfc-office-order-modal');
    var body = document.getElementById('wfc-office-order-body');
    overlay.classList.add('active');
    body.innerHTML = '<p style="color:#94a3b8;">Loading...</p>';
    try {
        var resp = await fetch('/api/office-orders/' + id);
        var j = await resp.json();
        if (!j.success) { body.innerHTML = '<p style="color:#dc2626;">Failed to load details.</p>'; return; }
        var d = j.data;
        var employees = (d.employees || []);
        var empList = employees.length
            ? '<ul class="wfc-order-emp-list">' + employees.map(function (e) {
                return '<li><strong>' + wfcEscapeHtml(e.name) + '</strong>' + (e.designation ? ' — ' + wfcEscapeHtml(e.designation) : '') + '</li>';
            }).join('') + '</ul>'
            : '<p style="color:#94a3b8;">No employees listed.</p>';

        body.innerHTML =
            wfcOrderRow('Order No.', wfcEscapeHtml(d.office_order_num)) +
            wfcOrderRow('Subject', wfcEscapeHtml(d.subject)) +
            wfcOrderRow('Issued Date', wfcFormatDate(d.issued_date)) +
            wfcOrderRow('Effective Date', wfcFormatDate(d.effective_date)) +
            wfcOrderRow('Status', wfcEscapeHtml(d.status)) +
            (d.status === 'Cancelled' ? wfcOrderRow('Cancellation Reason', wfcEscapeHtml(d.cancellation_reason)) : '') +
            wfcOrderRow('Issued By', d.issued_by ? wfcEscapeHtml(d.issued_by.name) + (d.issued_by.designation ? ' — ' + wfcEscapeHtml(d.issued_by.designation) : '') : '-') +
            '<div class="wfc-order-section-title">Details</div>' +
            '<div class="wfc-order-row-value">' + wfcEscapeHtml(d.details) + '</div>' +
            '<div class="wfc-order-section-title">Employees (' + employees.length + ')</div>' +
            empList;
    } catch (err) {
        body.innerHTML = '<p style="color:#dc2626;">Failed to load details.</p>';
    }
}

function closeWfcOfficeOrderModal() {
    document.getElementById('wfc-office-order-modal').classList.remove('active');
}

document.getElementById('wfc-office-order-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeWfcOfficeOrderModal();
    }
});

async function wfcOpenTravelOrder(id) {
    var overlay = document.getElementById('wfc-travel-order-modal');
    var body = document.getElementById('wfc-travel-order-body');
    overlay.classList.add('active');
    body.innerHTML = '<p style="color:#94a3b8;">Loading...</p>';
    try {
        var resp = await fetch('/api/travel-orders/' + id);
        var j = await resp.json();
        if (!j.success) { body.innerHTML = '<p style="color:#dc2626;">Failed to load details.</p>'; return; }
        var d = j.data;
        var employees = (d.employees || []);
        var empList = employees.length
            ? '<ul class="wfc-order-emp-list">' + employees.map(function (e) {
                return '<li><strong>' + wfcEscapeHtml(e.name) + '</strong>' + (e.designation ? ' — ' + wfcEscapeHtml(e.designation) : '') + '</li>';
            }).join('') + '</ul>'
            : '<p style="color:#94a3b8;">No employees listed.</p>';

        body.innerHTML =
            wfcOrderRow('Order No.', wfcEscapeHtml(d.travel_order_num)) +
            wfcOrderRow('Destination', wfcEscapeHtml(d.destination)) +
            wfcOrderRow('Departure', wfcFormatDate(d.departure)) +
            wfcOrderRow('Return', wfcFormatDate(d.return)) +
            wfcOrderRow('Purpose', wfcEscapeHtml(d.purpose)) +
            wfcOrderRow('Report To', wfcEscapeHtml(d.report_to)) +
            wfcOrderRow('Per Diem / Expenses', wfcEscapeHtml(d.per_diem)) +
            wfcOrderRow('Appropriation', wfcEscapeHtml(d.appropriation)) +
            wfcOrderRow('Remarks', wfcEscapeHtml(d.remarks)) +
            wfcOrderRow('Status', wfcEscapeHtml(d.status)) +
            wfcOrderRow('Created By', wfcEscapeHtml(d.created_by)) +
            wfcOrderRow('Recommender', wfcEscapeHtml(d.recommender)) +
            '<div class="wfc-order-section-title">Employees (' + employees.length + ')</div>' +
            empList;
    } catch (err) {
        body.innerHTML = '<p style="color:#dc2626;">Failed to load details.</p>';
    }
}

function closeWfcTravelOrderModal() {
    document.getElementById('wfc-travel-order-modal').classList.remove('active');
}

document.getElementById('wfc-travel-order-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeWfcTravelOrderModal();
    }
});
</script>
@endsection
