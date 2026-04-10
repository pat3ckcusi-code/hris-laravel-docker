@extends('dashboards.layout', [
    'title' => 'Department Statistics',
    'subtitle' => 'Employee ETA / Locator Usage',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

<?php
    $displayMonth = request()->query('month') && (int)request()->query('month') > 0 ? (int)request()->query('month') : (int)date('n');
    $displayYear  = request()->query('year') && (int)request()->query('year') > 0 ? (int)request()->query('year') : (int)date('Y');
    $user = auth()->user();
    if ($user && isset($user->access_level) && stripos($user->access_level, 'administrative') !== false) {
        $apiUrl = route('admin-officer.statistics.data');
    } else {
        $apiUrl = route('department-head.statistics.data');
    }
?>

@section('content')
<!-- Content Header -->
<div class="top">
    <div>
        <h1>Employee ETA / Locator Usage</h1>
    </div>
    <div class="stats-actions">
        <button class="month-nav" id="monthToday" data-month="{{ date('n') }}" data-year="{{ date('Y') }}">This Month</button>
    </div>
    
</div>

<script>
window._employeeStatisticsApiUrl = window._employeeStatisticsApiUrl || '{{ $apiUrl ?? route("department-head.statistics.data") }}';

function formatBadge(count, type) {
    let cls = 'badge-usage';
    if (type === 'eta') cls += count > 0 ? ' info' : '';
    if (type === 'locator') cls += count > 0 ? ' warning' : '';
    if (type === 'total') cls += count >= 5 ? ' danger' : (count >= 3 ? ' warning' : ' success');
    return `<span class="${cls}">${count}</span>`;
}

function formatDateStr(dateStr) {
    if (!dateStr) return '';
    try {
        let s = String(dateStr).trim();
        if (s.indexOf(' ') !== -1 && s.indexOf('T') === -1 && s.indexOf('-') !== -1) s = s.replace(' ', 'T');
        const d = new Date(s);
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (e) { return dateStr; }
}

function formatTimeStr(timeStr) {
    if (!timeStr) return '';
    try {
        let s = String(timeStr).trim();
        if (/^\d{1,2}:\d{2}(:\d{2})?$/.test(s)) s = '1970-01-01T' + s;
        else if (s.indexOf(' ') !== -1 && s.indexOf('T') === -1) s = s.replace(' ', 'T');
        const d = new Date(s);
        if (isNaN(d)) return timeStr;
        return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (e) { return timeStr; }
}

function clearBodyAndMessage(message) {
    const tbody = document.getElementById('statsBody');
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted">${message}</td></tr>`;
}

function renderRows(rows) {
    // store rows and render first page
    window._stats_rows = Array.isArray(rows) ? rows : [];
    window._stats_per_page = 5;
    renderPage(1);
}

function renderPage(page) {
    const rows = window._stats_rows || [];
    const per = window._stats_per_page || 5;
    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / per));
    const current = Math.min(Math.max(1, parseInt(page || 1, 10)), totalPages);
    const start = (current - 1) * per;
    const slice = rows.slice(start, start + per);

    const tbody = document.getElementById('statsBody');
    tbody.innerHTML = '';
    if (!slice || slice.length === 0) {
        clearBodyAndMessage('No ETA / Locator usage records found.');
        renderPagination(totalPages, current);
        return;
    }

    const currentMonth = window._stats_current_month || {{ (int)($displayMonth ?? date('n')) }};
    const currentYear  = window._stats_current_year  || {{ (int)($displayYear ?? date('Y')) }};

    slice.forEach(row => {
        const tr = document.createElement('tr');
        const tdEmp = document.createElement('td'); tdEmp.textContent = row.EmpNo || '';
        const tdName = document.createElement('td'); tdName.className = 'text-truncate'; tdName.textContent = [row.Lname, row.Fname, row.Mname, row.Extension].filter(Boolean).join(', ');
        const tdDept = document.createElement('td'); tdDept.textContent = row.Dept || '';

        const eta = parseInt(row.eta_count) || 0;
        const locator = parseInt(row.locator_count) || 0;
        const leave = parseInt(row.leave_count) || 0;
        const totalUsage = parseInt(row.total_usage) || 0;

        const tdLeave = document.createElement('td'); tdLeave.style.textAlign = 'center'; tdLeave.innerHTML = `<a href="#" class="usage-link" data-emp="${row.EmpNo || ''}" data-type="Leave" data-month="${currentMonth}" data-year="${currentYear}">${formatBadge(leave,'total')}</a>`;
        const tdEta = document.createElement('td'); tdEta.style.textAlign = 'center'; tdEta.innerHTML = `<a href="#" class="usage-link" data-emp="${row.EmpNo || ''}" data-type="ETA" data-month="${currentMonth}" data-year="${currentYear}">${formatBadge(eta,'eta')}</a>`;
        const tdLocator = document.createElement('td'); tdLocator.style.textAlign = 'center'; tdLocator.innerHTML = `<a href="#" class="usage-link" data-emp="${row.EmpNo || ''}" data-type="Locator" data-month="${currentMonth}" data-year="${currentYear}">${formatBadge(locator,'locator')}</a>`;
        const tdTotal = document.createElement('td'); tdTotal.style.textAlign = 'center'; tdTotal.innerHTML = formatBadge(totalUsage,'total');

        tr.appendChild(tdEmp); tr.appendChild(tdName); tr.appendChild(tdDept); tr.appendChild(tdLeave); tr.appendChild(tdEta); tr.appendChild(tdLocator); tr.appendChild(tdTotal);
        tbody.appendChild(tr);
    });

    renderPagination(totalPages, current);
}

function renderPagination(totalPages, currentPage) {
    const container = document.getElementById('statsPagination');
    if (!container) return;
    container.innerHTML = '';
    const info = document.createElement('div'); info.style.fontSize = '0.95rem'; info.style.color = '#475569'; info.textContent = `Page ${currentPage} of ${totalPages}`;
    const btnPrev = document.createElement('button'); btnPrev.className = 'month-nav'; btnPrev.textContent = 'Prev'; btnPrev.dataset.page = Math.max(1, currentPage - 1);
    const btnNext = document.createElement('button'); btnNext.className = 'month-nav'; btnNext.textContent = 'Next'; btnNext.dataset.page = Math.min(totalPages, currentPage + 1);
    btnPrev.disabled = currentPage <= 1; btnNext.disabled = currentPage >= totalPages;
    btnPrev.addEventListener('click', (e) => { renderPage(parseInt(e.currentTarget.dataset.page, 10)); });
    btnNext.addEventListener('click', (e) => { renderPage(parseInt(e.currentTarget.dataset.page, 10)); });
    container.appendChild(btnPrev); container.appendChild(info); container.appendChild(btnNext);
}

async function fetchStats(month, year) {
    clearBodyAndMessage('Loading...');
    try {
        const url = new URL(window._employeeStatisticsApiUrl, window.location.origin);
        url.searchParams.set('month', month);
        url.searchParams.set('year', year);
        console.debug('Fetching stats from:', url.toString());
        const resp = await fetch(url.toString(), { credentials: 'include' });
        if (!resp.ok) {
            console.error('Statistics request failed', resp.status, resp.statusText);
            const txt = await resp.text().catch(()=>null);
            console.debug('Response body:', txt);
            clearBodyAndMessage('Failed to load data. (HTTP ' + resp.status + ')');
            return;
        }
        let data;
        try { data = await resp.json(); } catch (jsonErr) {
            console.error('Failed to parse JSON', jsonErr);
            const txt = await resp.text().catch(()=>null);
            console.debug('Response body (non-json):', txt);
            clearBodyAndMessage('Failed to load data. (Invalid JSON)');
            return;
        }
        if (data && data.success) {
            window._stats_current_month = month;
            window._stats_current_year = year;
            renderRows(data.data);
        } else {
            console.error('Statistics returned error payload', data);
            clearBodyAndMessage('Failed to load data.');
        }
    } catch (e) {
        console.error('Statistics fetch exception', e);
        clearBodyAndMessage('Failed to load data.');
    }
}

function updateNavButtons(container, month, year) {
    if (!container) return;
    const prevBtn = container.querySelector('.month-nav[data-dir="prev"]') || container.querySelector('.month-nav.prev');
    const nextBtn = container.querySelector('.month-nav[data-dir="next"]') || container.querySelector('.month-nav.next');
    const cur = new Date(year, month - 1, 1);
    const prev = new Date(cur); prev.setMonth(cur.getMonth() - 1);
    const next = new Date(cur); next.setMonth(cur.getMonth() + 1);
    if (prevBtn) { prevBtn.dataset.month = prev.getMonth() + 1; prevBtn.dataset.year = prev.getFullYear(); }
    if (nextBtn) { nextBtn.dataset.month = next.getMonth() + 1; nextBtn.dataset.year = next.getFullYear(); }
}

document.addEventListener('click', function (e) {
    const el = e.target.closest('.month-nav');
    if (el) {
        e.preventDefault();
        const month = parseInt(el.dataset.month, 10);
        const year = parseInt(el.dataset.year, 10);
        // find the nearest card container that holds the nav and label
        const card = el.closest('.card') || document.querySelector('.card');
        const label = card ? card.querySelector('.font-weight-bold') : null;
        if (label) { label.textContent = new Date(year, month - 1).toLocaleString(undefined, { month: 'long' }) + ' ' + year; }
        updateNavButtons(card || document.querySelector('.card'), month, year);
        fetchStats(month, year);
        return;
    }

    const usage = e.target.closest('.usage-link');
    if (usage) {
        e.preventDefault();
        const empNo = usage.dataset.emp;
        const type = usage.dataset.type;
        const month = usage.dataset.month || window._stats_current_month || {{ (int)($displayMonth ?? date('n')) }};
        const year = usage.dataset.year || window._stats_current_year || {{ (int)$displayYear }};
        const url = new URL('{{ route('department-head.statistics.details') }}', window.location.origin);
        url.searchParams.set('empNo', empNo);
        url.searchParams.set('type', type);
        url.searchParams.set('month', month);
        url.searchParams.set('year', year);
        fetch(url.toString(), { credentials: 'include' })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(resp => {
                if (!resp || !resp.success) { alert('Failed to load details'); return; }
                if (type === 'ETA') {
                    const body = document.getElementById('etaModalBody');
                    body.innerHTML = '';
                    if (!resp.data.length) { body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No records for selected month.</td></tr>'; }
                    else {
                        resp.data.forEach(r => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `<td>${formatDateStr(r.travel_date)}</td><td>${(r.business_type||'')}</td><td>${(r.destination||'')}</td><td>${(r.travel_detail||'')}</td>`;
                            body.appendChild(tr);
                        });
                    }
                    const dlg = document.getElementById('etaUsageModal'); if (dlg && dlg.showModal) dlg.showModal();
                } else if (type === 'Locator') {
                    const body = document.getElementById('locatorModalBody'); body.innerHTML = '';
                    if (!resp.data.length) { body.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No records for selected month.</td></tr>'; }
                    else {
                        resp.data.forEach(r => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `<td>${formatDateStr(r.travel_date)}</td><td>${formatTimeStr(r.intended_departure)}</td><td>${formatTimeStr(r.intended_arrival)}</td><td>${(r.destination||'')}</td><td>${(r.business_type||'')}</td><td>${(r.travel_detail||'')}</td><td>${formatTimeStr(r.Arrival_Time)||formatTimeStr(r.arrival_date)||''}</td>`;
                            body.appendChild(tr);
                        });
                    }
                    const dlg = document.getElementById('locatorUsageModal'); if (dlg && dlg.showModal) dlg.showModal();
                } else if (type === 'Leave') {
                    const body = document.getElementById('leaveModalBody'); body.innerHTML = '';
                    if (!resp.data.length) { body.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No records for selected month.</td></tr>'; }
                    else {
                        resp.data.forEach(r => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `<td>${formatDateStr(r.start_date)}</td><td>${formatDateStr(r.end_date)}</td><td>${(r.leave_type||'')}</td><td>${(r.total_days||'')}</td><td>${(r.reason||'')}</td>`;
                            body.appendChild(tr);
                        });
                    }
                    const dlg = document.getElementById('leaveUsageModal'); if (dlg && dlg.showModal) dlg.showModal();
                }
            })
            .catch(err => { console.error('Details fetch failed', err); alert('Failed to load details'); });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    // target the card container where Prev/Next buttons are located
    updateNavButtons(document.querySelector('.card'), {{ (int)($displayMonth ?? date('n')) }}, {{ (int)($displayYear ?? date('Y')) }});
    fetchStats({{ (int)($displayMonth ?? date('n')) }}, {{ (int)($displayYear ?? date('Y')) }});
});
</script>

<!-- Card with table -->
<section>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div style="display:flex;gap:10px;align-items:center;">
                <?php
                    $dm = $displayMonth ?? date('n');
                    $dy = $displayYear ?? date('Y');
                    $prev = (new DateTime())->setDate($dy, $dm, 1)->modify('-1 month');
                    $next = (new DateTime())->setDate($dy, $dm, 1)->modify('+1 month');
                ?>
                <button class="month-nav" data-dir="prev" data-month="{{ $prev->format('n') }}" data-year="{{ $prev->format('Y') }}">&laquo; Prev</button>
                <div class="font-weight-bold">{{ date('F', mktime(0,0,0,$dm,1,$dy)) }} {{ $dy }}</div>
                <button class="month-nav" data-dir="next" data-month="{{ $next->format('n') }}" data-year="{{ $next->format('Y') }}">Next &raquo;</button>
            </div>
            <div>
                <button class="month-nav" id="monthToday" data-month="{{ date('n') }}" data-year="{{ date('Y') }}">This Month</button>
            </div>
        </div>

        <div style="overflow:auto;">
            <table class="stats-table leave-table">
                <thead>
                    <tr>
                        <th>Employee Number</th>
                        <th>Employee Name</th>
                        <th>Department</th>
                        <th class="text-center">Leave</th>
                        <th class="text-center">ETA Usage</th>
                        <th class="text-center">Locator Usage</th>
                        <th class="text-center">Total Usage</th>
                    </tr>
                </thead>
                <tbody id="statsBody">
                    <tr><td colspan="7" class="text-center text-muted">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="statsPagination" style="margin-top:12px;display:flex;justify-content:flex-end;align-items:center;gap:8px;"></div>

        <div style="margin-top:12px;color:#64748b;font-size:0.95rem;">Showing ETA, Locator and Leave usage based on approved applications</div>
    </div>
</section>

<!-- Modals using native dialog -->
<dialog id="etaUsageModal" class="employee-modal" style="max-width:900px;width:90%;">
    <div class="dialog-header">
        <h3 class="dialog-title">ETA Usage Details</h3>
        <button class="dialog-close" aria-label="Close" onclick="document.getElementById('etaUsageModal').close()">✕</button>
    </div>
    <div class="dialog-body">
        <div style="overflow:auto;">
            <table class="stats-table dialog-table leave-table" style="min-width:600px;">
                <thead><tr><th>Travel Date</th><th>Business Type</th><th>Destination</th><th>Travel Detail</th></tr></thead>
                <tbody id="etaModalBody"><tr><td colspan="4" class="text-center text-muted">No records.</td></tr></tbody>
            </table>
        </div>
    </div>
</dialog>

<dialog id="locatorUsageModal" class="employee-modal" style="max-width:1000px;width:95%;">
    <div class="dialog-header">
        <h3 class="dialog-title">Locator Usage Details</h3>
        <button class="dialog-close" aria-label="Close" onclick="document.getElementById('locatorUsageModal').close()">✕</button>
    </div>
    <div class="dialog-body">
        <div style="overflow:auto;">
            <table class="stats-table dialog-table leave-table" style="min-width:800px;">
                <thead><tr><th>Travel Date</th><th>Intended Departure</th><th>Intended Arrival</th><th>Destination</th><th>Business Type</th><th>Travel Detail</th><th>Arrival Time</th></tr></thead>
                <tbody id="locatorModalBody"><tr><td colspan="7" class="text-center text-muted">No records.</td></tr></tbody>
            </table>
        </div>
    </div>
</dialog>

<dialog id="leaveUsageModal" class="employee-modal" style="max-width:900px;width:90%;">
    <div class="dialog-header">
        <h3 class="dialog-title">Leave Usage Details</h3>
        <button class="dialog-close" aria-label="Close" onclick="document.getElementById('leaveUsageModal').close()">✕</button>
    </div>
    <div class="dialog-body">
        <div style="overflow:auto;">
            <table class="stats-table dialog-table leave-table" style="min-width:700px;">
                <thead><tr><th>Start Date</th><th>End Date</th><th>Leave Type</th><th>Days</th><th>Reason</th></tr></thead>
                <tbody id="leaveModalBody"><tr><td colspan="5" class="text-center text-muted">No records.</td></tr></tbody>
            </table>
        </div>
    </div>
</dialog>

@endsection
