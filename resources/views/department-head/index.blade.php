@extends('dashboards.layout', [
    'title' => 'Department Head',
    'subtitle' => 'Overview for Department Heads',
])

@section('tiles')
    <article class="tile">
        <strong>Pending Requests</strong>
        <div>Pending: {{ number_format($pendingCount ?? 0) }}</div>
        <div style="font-size:0.9rem;color:#666">Click the Leave/ETA cards to manage approvals.</div>
    </article>
    <article class="tile">
        <strong>Reports</strong>
        Quick statistics and summaries.
    </article>
@endsection

@section('content')
    <div class="kpi-grid">
        <div class="kpi-card accent-workforce" data-action="/department/workforce" data-modal="true" data-type="workforce" id="kpi-workforce">
            <div>
                <div class="kpi-head">
                    <div class="kpi-icon" aria-hidden="true">
                        <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
                    </div>
                    <div class="kpi-title">Workforce & Attendance</div>
                </div>
                <div class="kpi-value" id="val-workforce">—</div>
                <div class="kpi-meta">Active employees / monitored today</div>
            </div>
        </div>

        <div class="kpi-card accent-attendance" data-action="/department/attendance" data-modal="true" data-type="attendance" id="kpi-attendance">
            <div>
                <div class="kpi-head">
                    <div class="kpi-icon" aria-hidden="true">
                        <i class="fa-solid fa-clock" aria-hidden="true"></i>
                    </div>
                    <div class="kpi-title">Daily Attendance & Tardiness</div>
                </div>
                <div class="kpi-value" id="val-attendance">—</div>
                <div class="kpi-meta">Present / Late today</div>
            </div>
        </div>

        <div class="kpi-card accent-leave" data-action="/department/leave-requests" data-modal="true" data-type="leave" id="kpi-leave">
            <div>
                <div class="kpi-head">
                    <div class="kpi-icon" aria-hidden="true">
                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                    </div>
                    <div class="kpi-title">Leave Requests</div>
                </div>
                <div class="kpi-value" id="val-leave">—</div>
                <div class="kpi-meta">Pending / Approved</div>
            </div>
        </div>

        <div class="kpi-card accent-locator" data-action="/department/locator-requests" data-modal="true" data-type="locator" id="kpi-locator">
            <div>
                <div class="kpi-head">
                    <div class="kpi-icon" aria-hidden="true">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                    </div>
                    <div class="kpi-title">Locator Requests</div>
                </div>
                <div class="kpi-value" id="val-locator">—</div>
                <div class="kpi-meta">Pending / Resolved</div>
            </div>
        </div>

        <div class="kpi-card accent-eta" data-action="/department/eta-requests" data-modal="true" data-type="eta" id="kpi-eta">
            <div>
                <div class="kpi-head">
                    <div class="kpi-icon" aria-hidden="true">
                        <i class="fa-solid fa-plane-departure" aria-hidden="true"></i>
                    </div>
                    <div class="kpi-title">ETA Requests</div>
                </div>
                <div class="kpi-value" id="val-eta">—</div>
                <div class="kpi-meta">Pending / Approved</div>
            </div>
        </div>

        <div class="kpi-card accent-overtime" data-action="/department/overtime" data-modal="true" data-type="overtime" id="kpi-overtime">
            <div>
                <div class="kpi-head">
                    <div class="kpi-icon" aria-hidden="true">
                        <i class="fa-solid fa-stopwatch" aria-hidden="true"></i>
                    </div>
                    <div class="kpi-title">Overtime & Shift Schedules</div>
                </div>
                <div class="kpi-value" id="val-overtime">—</div>
                <div class="kpi-meta">Overtime hours / Open shifts</div>
            </div>
        </div>
    </div>

    <section class="tile">
        <strong>Quick Actions</strong>
        Click any card to view details and manage requests.
    </section>

    <!-- KPI Modal (reusable) -->
    <div id="kpi-modal-overlay" role="dialog" aria-hidden="true">
        <div class="kpi-modal" role="document" aria-modal="true">
            <div class="modal-head">
                <div class="modal-title" id="kpi-modal-title">Details</div>
                <div>
                    <button class="modal-close" id="kpi-modal-close" aria-label="Close">✕</button>
                </div>
            </div>
            <div class="modal-body" id="kpi-modal-body">
                <p>Loading...</p>
            </div>
        </div>
    </div>
@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const mapping = {
        workforce: document.getElementById('val-workforce'),
        attendance: document.getElementById('val-attendance'),
        leave: document.getElementById('val-leave'),
        locator: document.getElementById('val-locator'),
        eta: document.getElementById('val-eta'),
        overtime: document.getElementById('val-overtime'),
    };

    // Make cards clickable: open modal when `data-modal="true"`, otherwise navigate
    const modalOverlay = document.getElementById('kpi-modal-overlay');
    const modalTitle = document.getElementById('kpi-modal-title');
    const modalBody = document.getElementById('kpi-modal-body');
    const modalClose = document.getElementById('kpi-modal-close');

    function closeModal() {
        modalOverlay.style.display = 'none';
        modalOverlay.setAttribute('aria-hidden', 'true');
        modalBody.innerHTML = '';
    }

    modalClose.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', function (e) {
        if (e.target === modalOverlay) closeModal();
    });

    async function showCardModal(type, title) {
        modalTitle.textContent = title || 'Details';
        modalBody.innerHTML = '<p>Loading...</p>';
        modalOverlay.style.display = 'flex';
        modalOverlay.setAttribute('aria-hidden', 'false');

            try {
                if (type === 'workforce') {
                    // Load employees list endpoint and render DataTable
                    const res2 = await fetch('/api/department/employees-on-duty');
                    if (!res2.ok) throw res2;
                    const json2 = await res2.json();
                    const rows = (json2 && json2.data) ? json2.data : [];

                    function getStatusBadge(status) {
                        const s = (status || 'In Office').toLowerCase();
                        let bg;
                        if (s === 'on leave')                    bg = '#2563eb';
                        else if (s === 'out on eta')             bg = '#fbff00';
                        else if (s.startsWith('out for locator')) bg = '#d97706';
                        else if (s === 'absent')                 bg = '#dc2626';
                        else if (s === 'late')                   bg = '#ca8a04';
                        else                                     bg = '#16a34a';
                        return `<span style="display:inline-block;padding:4px 10px;border-radius:6px;background:${bg};color:#fff;font-weight:700;font-size:0.82rem">${status || 'In Office'}</span>`;
                    }

                    let table = '<table id="kpi-workforce-table" class="display" style="width:100%">';
                    table += '<thead><tr><th>Employee Number</th><th>Employee Name</th><th>Position</th><th>Status</th></tr></thead><tbody>';
                    rows.forEach(r => {
                        table += `<tr><td>${r.EmpNo || ''}</td><td>${r.name || ''}</td><td>${r.position || ''}</td><td>${getStatusBadge(r.status)}</td></tr>`;
                    });
                    table += '</tbody></table>';

                    modalBody.innerHTML = table;

                    // Initialize DataTable if available
                    if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
                        $('#kpi-workforce-table').DataTable({ pageLength: 10, lengthChange: false });
                    }
                } else {
                    // For leave/locator/eta, call dedicated endpoints to load tables
                    if (type === 'leave') {
                        const resL = await fetch('/api/department/leave-requests');
                        if (!resL.ok) throw resL;
                        const js = await resL.json();
                        const rowsL = (js && js.data) ? js.data : [];
                        let tableL = '<table id="kpi-leave-table" class="display" style="width:100%">';
                        tableL += '<thead><tr><th>#</th><th>Employee</th><th>Type</th><th>Start</th><th>End</th><th>Status</th></tr></thead><tbody>';
                        rowsL.forEach(r => {
                            tableL += `<tr><td>LR#${r.id}</td><td>${r.emp}</td><td>${r.type}</td><td>${r.start}</td><td>${r.end}</td><td>${r.status}</td></tr>`;
                        });
                        tableL += '</tbody></table>';
                        modalBody.innerHTML = tableL;
                        if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
                            $('#kpi-leave-table').DataTable({ pageLength: 10, lengthChange: false });
                        }
                    } else if (type === 'locator') {
                        const resLo = await fetch('/api/department/locator-requests');
                        if (!resLo.ok) throw resLo;
                        const js = await resLo.json();
                        const rows = (js && js.data) ? js.data : [];
                        let table = '<table id="kpi-locator-table" class="display" style="width:100%">';
                        table += '<thead><tr><th>#</th><th>Employee</th><th>Date</th><th>Location</th><th>Status</th></tr></thead><tbody>';
                        rows.forEach(r => {
                            table += `<tr><td>${r.id}</td><td>${r.emp}</td><td>${r.date}</td><td>${r.location}</td><td>${r.status}</td></tr>`;
                        });
                        table += '</tbody></table>';
                        modalBody.innerHTML = table;
                        if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
                            $('#kpi-locator-table').DataTable({ pageLength: 10, lengthChange: false });
                        }
                    } else if (type === 'eta') {
                        const resE = await fetch('/api/department/eta-requests');
                        if (!resE.ok) throw resE;
                        const js = await resE.json();
                        const rows = (js && js.data) ? js.data : [];
                        let table = '<table id="kpi-eta-table" class="display" style="width:100%">';
                        table += '<thead><tr><th>#</th><th>Employee</th><th>Departure</th><th>Destination</th><th>Status</th></tr></thead><tbody>';
                        rows.forEach(r => {
                            table += `<tr><td>${r.id}</td><td>${r.emp}</td><td>${r.departure}</td><td>${r.destination}</td><td>${r.status}</td></tr>`;
                        });
                        table += '</tbody></table>';
                        modalBody.innerHTML = table;
                        if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
                            $('#kpi-eta-table').DataTable({ pageLength: 10, lengthChange: false });
                        }
                    } else {
                        const res = await fetch(KPI_ROUTE);
                        if (!res.ok) throw res;
                        const j = await res.json();
                        const d = (j && j.data) ? j.data : {};
                        const m = d.metrics || {};
                        let html = '';
                        if (type === 'attendance') {
                            html += `<p><strong>Present:</strong> ${m.present_today ?? '—'}</p>`;
                            html += `<p><strong>Late:</strong> ${m.late_today ?? '—'}</p>`;
                            html += `<p>For full attendance details, go to the Attendance page.</p>`;
                        } else if (type === 'overtime') {
                            html += `<p><strong>Overtime hours:</strong> ${m.overtime_hours ?? '—'}</p>`;
                            html += `<p>Open shifts: —</p>`;
                        } else {
                            html += '<p>No details available.</p>';
                        }
                        modalBody.innerHTML = html;
                    }
                }
            } catch (e) {
                modalBody.innerHTML = '<p>Unable to load details.</p>';
            }
    }

    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function (ev) {
            const isModal = card.dataset.modal === 'true';
            const dest = card.dataset.action || '#';
            const type = card.dataset.type || '';
            const title = card.querySelector('.kpi-title') ? card.querySelector('.kpi-title').textContent.trim() : '';

            if (isModal) {
                ev.preventDefault();
                showCardModal(type, title);
                return;
            }

            if (dest === '#') return;
            window.location.href = dest;
        });
    });

    // Fetch KPI data from API and poll periodically
    const KPI_ROUTE = '/api/department/kpis';
    async function refreshKpis() {
        try {
            const res = await fetch(KPI_ROUTE);
            if (!res.ok) throw res;
            const j = await res.json();
            if (!j || !j.success) throw new Error('No data');
            const d = j.data || {};
            const m = d.metrics || {};

            mapping.workforce.textContent = (m.workforce_today !== undefined) ? m.workforce_today : (d.workforce_today ?? '—');
            mapping.attendance.textContent = (m.present_today !== undefined) ? (m.present_today + ' / ' + (m.late_today||0)) : (d.present_today ? (d.present_today + ' / ' + (d.late_today||0)) : '—');
            mapping.leave.textContent = (m.leave_pending !== undefined) ? (m.leave_pending + ' pending') : (d.leave_pending !== undefined ? (d.leave_pending + ' pending') : '—');
            mapping.locator.textContent = (m.locator_pending !== undefined) ? (m.locator_pending + ' pending') : (d.locator_pending !== undefined ? (d.locator_pending + ' pending') : '—');
            mapping.eta.textContent = (m.eta_pending !== undefined) ? (m.eta_pending + ' pending') : (d.eta_pending !== undefined ? (d.eta_pending + ' pending') : '—');
            mapping.overtime.textContent = (m.overtime_hours !== undefined) ? (m.overtime_hours + ' hrs') : (d.overtime_hours !== undefined ? (d.overtime_hours + ' hrs') : '—');
        } catch (e) {
            // leave placeholders
        }
    }

    // initial load + poll every 30s
    refreshKpis();
    const kpiInterval = setInterval(refreshKpis, 30000);

    // Detect Font Awesome availability and toggle fallback SVGs more reliably
    (function detectFa() {
        function runCheck() {
            document.querySelectorAll('.kpi-icon').forEach(icon => {
                const fa = icon.querySelector('i.fa-solid');
                const svg = icon.querySelector('svg.fallback');
                if (!fa) {
                    if (svg) svg.style.display = 'inline-block';
                    return;
                }

                // measure the rendered size of the <i> glyph — if very small, assume font/glyph missing
                const rect = fa.getBoundingClientRect();
                const hasGlyph = rect.width > 6 && rect.height > 6;
                if (hasGlyph) {
                    fa.style.display = 'inline-block';
                    if (svg) svg.style.display = 'none';
                } else {
                    fa.style.display = 'none';
                    if (svg) svg.style.display = 'inline-block';
                }
            });
        }

        // Run once on next frame, then again after fonts finish loading (if supported)
        requestAnimationFrame(runCheck);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(runCheck).catch(() => runCheck());
        } else {
            // If Font Loading API not available, re-run after a short delay
            setTimeout(runCheck, 500);
        }
    })();
});
</script>
@endsection
