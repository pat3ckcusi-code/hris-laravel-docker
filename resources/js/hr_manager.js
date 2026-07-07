import Swal from 'sweetalert2';
import Chart from 'chart.js/auto';

const colorSet = {
    blue: '#007bff',
    cyan: '#17a2b8',
    yellow: '#ffc107',
    green: '#28a745',
    indigo: '#6610f2',
    gray: '#6c757d',
    orange: '#fd7e14',
    red: '#dc3545',
};

// Permanent stays orange and Job Orders stays blue to match the chart's
// previous single-color default; other types fill in from the rest of the palette.
const employeeTypeColors = {
    'Permanent': colorSet.orange,
    'Job Orders': colorSet.blue,
    'Elected Officials': colorSet.indigo,
    'Co-Terminus': colorSet.cyan,
    'Casual': colorSet.yellow,
    'Contractual': colorSet.green,
    'Unspecified': colorSet.gray,
};

const createBarChart = (canvasId, label, payload, color = colorSet.blue, optionsOverride = {}) => {
    const ctx = document.getElementById(canvasId)?.getContext('2d');
    if (!ctx) return null;

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: payload?.labels || [],
            datasets: [
                {
                    label,
                    data: payload?.values || [],
                    backgroundColor: color,
                    borderRadius: 6,
                },
            ],
        },
        options: Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false,
                },
            },
        }, optionsOverride),
    });
};

const stackedDatasets = (payload) => (payload?.datasets || []).map((ds) => ({
    label: ds.label,
    data: ds.data,
    backgroundColor: employeeTypeColors[ds.label] || colorSet.gray,
    borderRadius: 4,
}));

const createStackedBarChart = (canvasId, payload, optionsOverride = {}) => {
    const ctx = document.getElementById(canvasId)?.getContext('2d');
    if (!ctx) return null;

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: payload?.labels || [],
            datasets: stackedDatasets(payload),
        },
        options: Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true },
                y: { stacked: true },
            },
            plugins: {
                legend: { display: true, position: 'bottom' },
            },
        }, optionsOverride),
    });
};

const updateStackedBarChart = (chart, payload) => {
    if (!chart) return;
    chart.data.labels = payload?.labels || [];
    chart.data.datasets = stackedDatasets(payload);
    chart.update();
};

const createPieChart = (canvasId, payload, colors, optionsOverride = {}) => {
    const ctx = document.getElementById(canvasId)?.getContext('2d');
    if (!ctx) return null;

    return new Chart(ctx, {
        type: 'pie',
        data: {
            labels: payload?.labels || [],
            datasets: [
                {
                    data: payload?.values || [],
                    backgroundColor: colors,
                },
            ],
        },
        options: Object.assign({
            responsive: true,
            maintainAspectRatio: false,
        }, optionsOverride),
    });
};

const updateChart = (chart, payload) => {
    if (!chart) return;
    chart.data.labels = payload?.labels || [];
    chart.data.datasets[0].data = payload?.values || [];
    chart.update();
};

const moduleRoot = document.querySelector('.hrm-module');
const dashboardRoot = document.querySelector('.hrm-dashboard');

const initializeWorkforceCharts = (root, initialData) => {
    if (!root) return;

    const charts = {
        totalWorkforceChart: createStackedBarChart(
            'totalWorkforceChart',
            initialData.workforce_per_department,
            {
                onClick: (evt, elements) => {
                    if (elements.length > 0) {
                        const { index, datasetIndex } = elements[0];
                        const department = charts.totalWorkforceChart.data.labels[index];
                        const type = charts.totalWorkforceChart.data.datasets[datasetIndex].label;
                        fetchEmployees({ department, employee_type: type }, `${type} — ${department}`);
                    }
                },
                scales: {
                    x: { stacked: true, display: false },
                    y: { stacked: true, title: { display: true, text: 'Total Employees' } },
                },
            }
        ),
        genderChart: createPieChart('genderChart', initialData.gender_distribution, [
            colorSet.cyan,
            colorSet.yellow,
            colorSet.gray,
        ]),
        employmentStatusChart: createPieChart(
            'employmentStatusChart',
            initialData.employment_status,
            [colorSet.green, colorSet.orange, colorSet.red, colorSet.gray],
            {
                plugins: {
                    title: { display: true, text: 'Employee Type' },
                },
            }
        ),
        ageGroupChart: createBarChart('ageGroupChart', 'Employees', initialData.age_group_distribution, colorSet.cyan),
        lengthOfServiceChart: createBarChart('lengthOfServiceChart', 'Employees', initialData.length_of_service, colorSet.green),
    };

    // Attach click handlers for pie and other charts to open filtered employee popups
    const attachChartClickHandlers = () => {
        // Include the currently-selected department/employee type (if any) so a chart
        // segment's drill-down list matches what the chart itself is scoped to, instead
        // of always pulling the org-wide total for that segment.
        const activeDepartment = () => filter?.value || undefined;
        const activeEmployeeType = () => typeFilter?.value || undefined;

        if (charts.genderChart) {
            charts.genderChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    fetchEmployees({ department: activeDepartment(), employee_type: activeEmployeeType(), gender: label }, `Employees: ${label}`);
                }
            };
            charts.genderChart.update();
        }

        if (charts.employmentStatusChart) {
            charts.employmentStatusChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    // filter by employee_type on click (the clicked segment is already the
                    // most specific value here, so it takes precedence over the dropdown)
                    fetchEmployees({ department: activeDepartment(), employee_type: label }, `Employees: ${label}`);
                }
            };
            charts.employmentStatusChart.update();
        }

        if (charts.ageGroupChart) {
            charts.ageGroupChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    fetchEmployees({ department: activeDepartment(), employee_type: activeEmployeeType(), age_group: label }, `Employees: ${label}`);
                }
            };
            charts.ageGroupChart.update();
        }

        if (charts.lengthOfServiceChart) {
            charts.lengthOfServiceChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    fetchEmployees({ department: activeDepartment(), employee_type: activeEmployeeType(), length_of_service: label }, `Employees: ${label}`);
                }
            };
            charts.lengthOfServiceChart.update();
        }
    };

    const chartUrl = root.dataset.chartUrl || '';
    const filter = document.getElementById('departmentFilter');
    const typeFilter = document.getElementById('employeeTypeFilter');

    const refreshCharts = (payload) => {
        updateStackedBarChart(charts.totalWorkforceChart, payload.workforce_per_department);
        updateChart(charts.genderChart, payload.gender_distribution);
        updateChart(charts.employmentStatusChart, payload.employment_status);
        updateChart(charts.ageGroupChart, payload.age_group_distribution);
        updateChart(charts.lengthOfServiceChart, payload.length_of_service);

        // Re-attach click handlers after chart updates
        attachChartClickHandlers();
    };

    const defaultEmployeeColumns = [
        { key: 'emp_no', label: 'Employee No' },
        { key: 'name', label: 'Name' },
        { key: 'position', label: 'Position' },
        { key: 'gender', label: 'Gender' },
        { key: 'age', label: 'Age' },
        { key: 'employee_type', label: 'Employee Type' },
        { key: 'date_hired', label: 'Date Hired' },
    ];

    // Fetch employees for a given set of filters (e.g. { department: 'IT', employee_type: 'Job Orders' }) and render popup
    const fetchEmployees = async (filters, title = 'Employees', columns = null) => {
        try {
            const params = new URLSearchParams();
            Object.entries(filters || {}).forEach(([key, value]) => {
                if (key && value !== undefined && value !== null) {
                    params.append(key, String(value));
                }
            });

            const url = `${root.dataset.chartUrl.replace(/chart-data$/, 'employees/filter')}?${params.toString()}`;
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error('Failed to fetch employees.');
            const data = await res.json();
            renderEmployeePopup(title, data || [], columns);
        } catch (err) {
            await Swal.fire('Error', 'Failed to load employee list.', 'error');
        }
    };

    const renderEmployeePopup = (title, employees, columns = null) => {
        const cols = columns || defaultEmployeeColumns;

        const tableHead = `<thead><tr>${cols.map((c) => `<th>${c.label}</th>`).join('')}</tr></thead>`;

        const bodyRows = employees
            .map((emp) => `<tr>${cols.map((c) => `<td>${emp[c.key] ?? ''}</td>`).join('')}</tr>`)
            .join('');

        const tableHtml = `
            <div style="max-height:60vh; overflow:auto; padding:8px; text-align:left;">
                <table class="table table-striped table-hover" style="width:100%">
                    ${tableHead}
                    <tbody>${bodyRows}</tbody>
                </table>
            </div>`;

        Swal.fire({
            title: title,
            html: tableHtml,
            width: '80%',
            showCloseButton: true,
            showConfirmButton: false,
            customClass: { popup: 'swal-wide' },
        });
    };

    const loadChartData = async () => {
        try {
            const params = new URLSearchParams();
            if (filter?.value) params.append('department', filter.value);
            if (typeFilter?.value) params.append('employee_type', typeFilter.value);
            const query = params.toString() ? `?${params.toString()}` : '';

            const response = await fetch(`${chartUrl}${query}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to fetch analytics data.');
            }

            const data = await response.json();
            refreshCharts(data);
        } catch (error) {
            await Swal.fire('Error', 'Failed to load chart data', 'error');
        }
    };

    if (filter) {
        filter.addEventListener('change', () => {
            loadChartData();
        });
    }

    if (typeFilter) {
        typeFilter.addEventListener('change', () => {
            loadChartData();
        });
    }

    // initial attach
    attachChartClickHandlers();

    // Clickable summary cards - award recipients and 60+ employees
    root.querySelectorAll('.hrm-summary-card[data-filter]').forEach((card) => {
        card.addEventListener('click', () => {
            const filter = card.dataset.filter;
            const title = card.dataset.title || 'Employees';

            if (filter === 'award_recipients') {
                fetchEmployees({ award_recipients: '1' }, title, [
                    { key: 'emp_no', label: 'Employee No' },
                    { key: 'name', label: 'Name' },
                    { key: 'department', label: 'Department' },
                    { key: 'date_hired', label: 'Date Hired' },
                    { key: 'years_of_service_int', label: 'Years of Service' },
                ]);
            } else if (filter === 'sixty_plus') {
                fetchEmployees({ sixty_plus: '1' }, title, [
                    { key: 'emp_no', label: 'Employee No' },
                    { key: 'name', label: 'Name' },
                    { key: 'department', label: 'Department' },
                    { key: 'age', label: 'Age' },
                    { key: 'date_hired', label: 'Date Hired' },
                ]);
            }
        });
    });
};

const renderPagination = (container, pagination, onPage) => {
    if (!container) return;

    const current = pagination?.current_page || 1;
    const last = pagination?.last_page || 1;

    if (last <= 1) {
        container.innerHTML = '';
        return;
    }

    const pages = [];
    const start = Math.max(1, current - 2);
    const end = Math.min(last, current + 2);

    pages.push(`<button type="button" data-page="${current - 1}" ${current === 1 ? 'disabled' : ''}>Prev</button>`);
    for (let page = start; page <= end; page += 1) {
        pages.push(`<button type="button" data-page="${page}" class="${page === current ? 'active' : ''}">${page}</button>`);
    }
    pages.push(`<button type="button" data-page="${current + 1}" ${current === last ? 'disabled' : ''}>Next</button>`);

    container.innerHTML = pages.join('');

    container.querySelectorAll('button[data-page]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) return;
            onPage(Number(button.dataset.page));
        });
    });
};

const bindLeaveModule = (root) => {
    if (!root || root.dataset.module !== 'leave') return;

    const department = document.getElementById('leaveDepartment');
    const status = document.getElementById('leaveStatus');
    const filterBtn = document.getElementById('leaveFilterBtn');
    const monthPicker = document.getElementById('leaveMonthPicker');

    const initialChart = JSON.parse(root.dataset.initialChart || '{"labels":[],"values":[]}');
    const leaveChart = createBarChart('leaveUsageChart', 'Leave Requests', initialChart, colorSet.orange);

    const fetchChart = async () => {
        const params = new URLSearchParams({
            department: department?.value || '',
            status: status?.value || 'pending',
            month: monthPicker?.value || '',
        });

        try {
            const response = await fetch(`${root.dataset.url}?${params.toString()}`);
            if (!response.ok) throw new Error('Leave data load failed.');

            const data = await response.json();
            updateChart(leaveChart, data.chart || { labels: [], values: [] });
        } catch (error) {
            await Swal.fire('Error', 'Failed to load leave chart data.', 'error');
        }
    };

    filterBtn?.addEventListener('click', fetchChart);

    monthPicker?.addEventListener('change', () => {
        const url = new URL(window.location.href);
        if (monthPicker.value) {
            url.searchParams.set('month', monthPicker.value);
        } else {
            url.searchParams.delete('month');
        }
        window.location.href = url.toString();
    });
};

const bindFrontdeskModule = (root) => {
    if (!root || root.dataset.module !== 'frontdesk') return;

    const tableBody = document.querySelector('#frontdeskTable tbody');
    const department = document.getElementById('frontdeskDepartment');
    const status = document.getElementById('frontdeskStatus');
    const filterBtn = document.getElementById('frontdeskFilterBtn');
    const paginationRoot = document.getElementById('frontdeskPagination');
    const csrf = root.dataset.csrf || '';

    const renderRows = (rows) => {
        if (!tableBody) return;

        tableBody.innerHTML = rows
            .map(
                (row) => `
                    <tr data-id="${row.id}" data-empno="${row.emp_no ?? ''}" data-name="${row.employee_name ?? ''}" data-doc="${row.document_type ?? ''}">
                        <td>${row.emp_no ?? ''}</td>
                        <td>${row.employee_name ?? ''}</td>
                        <td>${row.department ?? ''}</td>
                        <td>${row.document_type ?? ''}</td>
                        <td><span class="status-chip status-${row.status}">${(row.status || '').toUpperCase()}</span></td>
                        <td>${row.requested_on ?? ''}</td>
                        <td>
                            <button class="hrm-btn-secondary hrm-frontdesk-accept" type="button">Accept</button>
                            <button class="hrm-btn-secondary hrm-frontdesk-reject" type="button">Reject</button>
                            <button class="hrm-btn-secondary hrm-frontdesk-approve" type="button">Approve</button>
                            <button class="hrm-btn-secondary hrm-frontdesk-complete" type="button">Completed</button>
                            <button class="hrm-btn-secondary hrm-frontdesk-print" type="button">Print Certificate</button>
                        </td>
                    </tr>
                `
            )
            .join('');
    };

    const fetchRows = async (page = 1) => {
        const params = new URLSearchParams({
            department: department?.value || '',
            status: status?.value || 'all',
            page: String(page),
        });

        try {
            const response = await fetch(`${root.dataset.url}?${params.toString()}`);
            if (!response.ok) throw new Error('Front desk data load failed.');

            const data = await response.json();
            renderRows(data.rows || []);
            renderPagination(paginationRoot, data.pagination, fetchRows);
        } catch (error) {
            await Swal.fire('Error', 'Failed to load document requests.', 'error');
        }
    };

    const postAction = async (id, action, isComplete = false) => {
        const base = isComplete ? root.dataset.completeUrl : root.dataset.actionUrl;
        const actionUrl = (base || '').replace('__ID__', id);

        const response = await fetch(actionUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ action }),
        });

        if (!response.ok) {
            throw new Error('Status update failed.');
        }
    };

    const printCertificate = (row) => {
        const template = document.getElementById('certificateTemplate');
        if (!(template instanceof HTMLTemplateElement)) return;

        const html = template.innerHTML
            .replace('<span data-print="name"></span>', `<span>${row.dataset.name || ''}</span>`)
            .replace('<span data-print="empno"></span>', `<span>${row.dataset.empno || ''}</span>`)
            .replace('<span data-print="document"></span>', `<span>${row.dataset.doc || ''}</span>`)
            .replace('<span data-print="date"></span>', `<span>${new Date().toLocaleDateString()}</span>`);

        const printWindow = window.open('', '_blank', 'width=900,height=900');
        if (!printWindow) return;

        printWindow.document.write(`<html><head><title>Certificate</title></head><body>${html}</body></html>`);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    };

    filterBtn?.addEventListener('click', () => fetchRows(1));

    const initialPagination = JSON.parse(root.dataset.pagination || '{"current_page":1,"last_page":1}');
    renderPagination(paginationRoot, initialPagination, fetchRows);

    tableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        const row = event.target.closest('tr');
        if (!button || !row) return;

        const id = row.dataset.id;
        if (!id) return;

        try {
            if (button.classList.contains('hrm-frontdesk-print')) {
                printCertificate(row);
                return;
            }

            const confirm = await Swal.fire({
                title: 'Confirm workflow action?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes',
            });

            if (!confirm.isConfirmed) return;

            if (button.classList.contains('hrm-frontdesk-accept')) {
                await postAction(id, 'accept');
            }

            if (button.classList.contains('hrm-frontdesk-reject')) {
                await postAction(id, 'reject');
            }

            if (button.classList.contains('hrm-frontdesk-approve')) {
                await postAction(id, 'approve');
            }

            if (button.classList.contains('hrm-frontdesk-complete')) {
                await postAction(id, 'complete', true);
            }

            await Swal.fire('Success', 'Request workflow updated.', 'success');
            fetchRows();
        } catch (error) {
            await Swal.fire('Error', 'Failed to process action.', 'error');
        }
    });
};

const bindAuditModule = (root) => {
    if (!root || root.dataset.module !== 'audit') return;

    const tableBody = document.querySelector('#auditTable tbody');
    const user = document.getElementById('auditUser');
    const date = document.getElementById('auditDate');
    const action = document.getElementById('auditAction');
    const filterBtn = document.getElementById('auditFilterBtn');
    const paginationRoot = document.getElementById('auditPagination');

    const renderRows = (rows) => {
        if (!tableBody) return;

        tableBody.innerHTML = rows
            .map(
                (row) => `
                    <tr>
                        <td>${row.user || ''}</td>
                        <td>${row.role || ''}</td>
                        <td>${row.action || ''}</td>
                        <td>${row.timestamp || ''}</td>
                    </tr>
                `
            )
            .join('');
    };

    const fetchRows = async (page = 1) => {
        const params = new URLSearchParams({
            user: user?.value || '',
            date: date?.value || '',
            action: action?.value || '',
            page: String(page),
        });

        try {
            const response = await fetch(`${root.dataset.url}?${params.toString()}`);
            if (!response.ok) throw new Error('Audit data load failed.');

            const data = await response.json();
            renderRows(data.rows || []);
            renderPagination(paginationRoot, data.pagination, fetchRows);
        } catch (error) {
            await Swal.fire('Error', 'Failed to load audit logs.', 'error');
        }
    };

    filterBtn?.addEventListener('click', () => fetchRows(1));

    const initialPagination = JSON.parse(root.dataset.pagination || '{"current_page":1,"last_page":1}');
    renderPagination(paginationRoot, initialPagination, fetchRows);
};

const bindSimpleSuccessButtons = () => {
    document.querySelectorAll('.hrm-alert-success').forEach((button) => {
        button.addEventListener('click', async () => {
            await Swal.fire('Saved', 'Changes have been captured in this scaffold.', 'success');
        });
    });
};

// ── Enhancement 1: Alert Strip ────────────────────────────────────────────

const bindAlertPanel = (root) => {
    const strip = document.getElementById('hrmAlertStrip');
    const alertsUrl = root?.dataset?.alertsUrl;
    if (!strip || !alertsUrl) return;

    const chipColor = (type) => {
        if (type === 'red') return '#dc3545';
        if (type === 'orange') return '#fd7e14';
        return '#17a2b8';
    };

    const makeChip = (text, link, type) => {
        const a = link ? `href="${link}"` : '';
        return `<a ${a} class="hrm-alert-chip" style="border-left-color:${chipColor(type)}">${text}</a>`;
    };

    fetch(alertsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((data) => {
            const chips = [];

            if (data.open_payroll) {
                chips.push(makeChip(
                    `&#x26a0; Payroll run "${data.open_payroll.period}" is still in Draft`,
                    null, 'orange'
                ));
            }

            if (data.unresolved_exceptions > 0) {
                chips.push(makeChip(
                    `&#x26a0; ${data.unresolved_exceptions} unresolved payroll ${data.unresolved_exceptions === 1 ? 'exception' : 'exceptions'}`,
                    null, 'orange'
                ));
            }

            (data.upcoming_holidays || []).forEach((h) => {
                chips.push(makeChip(
                    `&#x1F4C5; ${h.title} on ${h.date} (in ${h.days_away} ${h.days_away === 1 ? 'day' : 'days'})`,
                    null, 'blue'
                ));
            });

            if (chips.length === 0) {
                strip.style.display = 'none';
                return;
            }

            strip.innerHTML = chips.join('');
            strip.style.display = 'flex';
        })
        .catch(() => { /* Silently ignore alert fetch failures */ });
};

// ── Enhancement 3: Leave Analytics ───────────────────────────────────────

const bindLeaveAnalytics = (root) => {
    const analyticsUrl = root?.dataset?.analyticsUrl;
    const notifyUrl = root?.dataset?.notifyUrl;
    const csrf = root?.dataset?.csrf || '';
    if (!analyticsUrl) return;

    const trendArrow = (trend) => {
        if (trend === 'down') return `<span style="color:#dc3545;font-weight:700;">&#x2193;</span>`;
        if (trend === 'up') return `<span style="color:#28a745;font-weight:700;">&#x2191;</span>`;
        return `<span style="color:#64748b;">&#x2013;</span>`;
    };

    let trendChart = null;

    fetch(analyticsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((data) => {
            // Balance summary table
            const balanceEl = document.getElementById('leaveBalanceTable');
            if (balanceEl && data.balance_summary) {
                const types = ['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'];
                const rows = types.map((t) => {
                    const b = data.balance_summary[t];
                    if (!b) return '';
                    return `<tr>
                        <td><strong>${t}</strong></td>
                        <td>${b.avg} days ${trendArrow(b.trend)}</td>
                        <td><span style="color:#fd7e14;">${b.low_count}</span></td>
                        <td><span style="color:#dc3545;">${b.zero_count}</span></td>
                    </tr>`;
                }).join('');

                balanceEl.innerHTML = `<table class="hrm-table">
                    <thead><tr><th>Type</th><th>Avg Balance (Trend)</th><th>Low (&lt;2d)</th><th>Exhausted</th></tr></thead>
                    <tbody>${rows || '<tr><td colspan="4" style="text-align:center;color:#94a3b8;">No data</td></tr>'}</tbody>
                </table>`;
            }

            // 6-month trend chart
            if (data.trend) {
                trendChart = new Chart(document.getElementById('leaveTrendChart')?.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: data.trend.labels,
                        datasets: [
                            { label: 'Submitted', data: data.trend.submitted, borderColor: colorSet.blue, backgroundColor: 'rgba(0,123,255,0.1)', tension: 0.3 },
                            { label: 'Approved', data: data.trend.approved, borderColor: colorSet.green, backgroundColor: 'rgba(40,167,69,0.1)', tension: 0.3 },
                        ],
                    },
                    options: { responsive: true, maintainAspectRatio: false },
                });
            }
        })
        .catch(() => { /* Silently ignore analytics load failure */ });
};

// ── Enhancement 5: Workforce Planning ────────────────────────────────────

const bindWorkforcePlanning = (root) => {
    const toggleBtn = document.getElementById('togglePlanningBtn');
    const panel = document.getElementById('workforcePlanningPanel');
    const planningUrl = root?.dataset?.planningUrl;
    if (!toggleBtn || !panel || !planningUrl) return;

    let loaded = false;
    let hiringChart = null;

    const loadPlanningData = () => {
        fetch(planningUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((data) => {
                // Headcount cards
                const hc = data.headcount || {};
                const pctSign = (v) => v >= 0 ? `+${v}` : `${v}`;

                const hiredEl = document.getElementById('planHired');
                if (hiredEl) {
                    hiredEl.querySelector('h3').textContent = hc.hired_30d ?? '-';
                    hiredEl.querySelector('small').textContent = `${pctSign(hc.hired_pct_change ?? 0)}% vs last month`;
                    hiredEl.querySelector('small').style.color = (hc.hired_pct_change ?? 0) >= 0 ? '#28a745' : '#dc3545';
                }

                const sepEl = document.getElementById('planSeparated');
                if (sepEl) {
                    sepEl.querySelector('h3').textContent = hc.separated_30d ?? '-';
                    sepEl.querySelector('small').textContent = `${pctSign(hc.separated_pct_change ?? 0)}% vs last month`;
                    sepEl.querySelector('small').style.color = (hc.separated_pct_change ?? 0) > 0 ? '#dc3545' : '#28a745';
                }

                const netEl = document.getElementById('planNet');
                if (netEl) {
                    const net = hc.net ?? 0;
                    netEl.querySelector('h3').textContent = `${net >= 0 ? '+' : ''}${net}`;
                    netEl.querySelector('h3').style.color = net >= 0 ? '#28a745' : '#dc3545';
                }

                // Hiring trend chart
                if (data.trend && !hiringChart) {
                    hiringChart = createBarChart('hiringTrendChart', 'New Hires', data.trend, colorSet.cyan);
                }
            })
            .catch(() => { /* Silently ignore */ });
    };

    toggleBtn.addEventListener('click', () => {
        const isHidden = panel.style.display === 'none';
        panel.style.display = isHidden ? 'block' : 'none';
        toggleBtn.innerHTML = isHidden
            ? '<i class="fas fa-chart-line"></i> Hide Workforce Insights'
            : '<i class="fas fa-chart-line"></i> Show Workforce Insights';

        if (isHidden && !loaded) {
            loaded = true;
            loadPlanningData();
        }
    });

    loaded = true;
    loadPlanningData();
};

// ── Service Milestones (standalone page) ─────────────────────────────────

const bindServiceMilestonesModule = (root) => {
    if (!root || root.dataset.module !== 'service-milestones') return;

    const container = document.getElementById('milestonesTable');
    const planningUrl = root.dataset.planningUrl;
    if (!container || !planningUrl) return;

    const milestoneLabel = (years) => {
        if (years >= 30) return `<span class="hrm-milestone-badge hrm-milestone-30">&#9733;&#9733;&#9733; ${years} YRS</span>`;
        if (years >= 20) return `<span class="hrm-milestone-badge hrm-milestone-20">&#9733;&#9733; ${years} YRS</span>`;
        return `<span class="hrm-milestone-badge hrm-milestone-10">&#9733; ${years} YRS</span>`;
    };

    const milestoneYears = [10, 15, 20, 25, 30];
    const cardEls = milestoneYears.map((years) => document.getElementById(`msYear${years}`));
    const clearBtn = document.getElementById('msClearFilter');

    let allMilestones = [];
    let activeYear = null;

    const renderCards = (ms) => {
        milestoneYears.forEach((years) => {
            const el = document.getElementById(`msYear${years}`);
            if (!el) return;
            el.querySelector('h3').textContent = ms.filter((m) => m.years === years).length;
        });
    };

    const renderTable = (ms) => {
        if (ms.length === 0) {
            container.innerHTML = activeYear
                ? `<p style="color:#94a3b8;padding:0.5rem 0;">No ${activeYear}-year milestones remaining this year.</p>`
                : `<p style="color:#94a3b8;padding:0.5rem 0;">No service milestones remaining this year.</p>`;
            return;
        }

        const rows = ms.map((m) => `<tr class="${m.days_away <= 7 ? 'ms-row-urgent' : ''}">
            <td>${m.name ?? ''}</td>
            <td>${m.department ?? ''}</td>
            <td>${milestoneLabel(m.years)}</td>
            <td>${m.anniversary ?? ''}</td>
            <td>${m.days_away === 0 ? 'Today!' : `In ${m.days_away} days`}</td>
        </tr>`).join('');

        container.innerHTML = `<table class="hrm-table">
            <thead><tr><th>Name</th><th>Department</th><th>Milestone</th><th>Anniversary</th><th>Days Away</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
    };

    const applyFilter = () => {
        cardEls.forEach((el) => {
            if (!el) return;
            const isActive = activeYear !== null && Number(el.dataset.year) === activeYear;
            el.classList.toggle('ms-card-active', isActive);
            el.setAttribute('aria-pressed', String(isActive));
        });

        if (clearBtn) clearBtn.style.display = activeYear === null ? 'none' : 'inline-flex';

        const filtered = activeYear === null ? allMilestones : allMilestones.filter((m) => m.years === activeYear);
        renderTable(filtered);
    };

    cardEls.forEach((el) => {
        if (!el) return;
        el.addEventListener('click', () => {
            const years = Number(el.dataset.year);
            activeYear = activeYear === years ? null : years;
            applyFilter();
        });
    });

    clearBtn?.addEventListener('click', () => {
        activeYear = null;
        applyFilter();
    });

    fetch(planningUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((data) => {
            allMilestones = data.milestones || [];
            renderCards(allMilestones);
            applyFilter();
        })
        .catch(() => {
            container.innerHTML = `<p style="color:#dc3545;padding:0.5rem 0;">Failed to load service milestones.</p>`;
        });
};

// ── Enhancement 2: Attendance Overview ───────────────────────────────────

const bindAttendanceOverview = () => {
    const root = document.querySelector('.hrm-module');
    if (!root || !root.dataset.url?.includes('attendance-overview')) return;

    const monthInput = document.getElementById('attendanceMonth');
    const deptSelect = document.getElementById('attendanceDepartment');
    const filterBtn = document.getElementById('attendanceFilterBtn');

    let trendChart = null;
    let deptChart = null;

    const badge = (val, cls) =>
        `<span class="att-badge att-badge-${cls}">${val}</span>`;

    const render = (data) => {
        // Summary cards
        document.getElementById('attTotalEmployees').querySelector('h3').textContent = data.summary?.total_employees ?? '-';
        document.getElementById('attAvgTardiness').querySelector('h3').textContent = data.summary?.avg_tardiness_minutes ?? '-';
        document.getElementById('attAvgUndertime').querySelector('h3').textContent = data.summary?.avg_undertime_minutes ?? '-';
        document.getElementById('attTotalAbsences').querySelector('h3').textContent = data.summary?.total_absences ?? '-';

        // 3-month trend multi-line chart
        const trend = data.trend || [];
        const trendLabels = trend.map((t) => t.month);
        const trendDatasets = [
            { label: 'Tardiness Days', data: trend.map((t) => t.tardiness_days), borderColor: colorSet.red, backgroundColor: 'rgba(220,53,69,0.08)', tension: 0.3, fill: true },
            { label: 'Undertime Days', data: trend.map((t) => t.undertime_days), borderColor: colorSet.orange, backgroundColor: 'rgba(255,165,0,0.08)', tension: 0.3, fill: true },
            { label: 'Absent Days', data: trend.map((t) => t.absent_days), borderColor: colorSet.blue, backgroundColor: 'rgba(0,123,255,0.08)', tension: 0.3, fill: true },
        ];
        if (!trendChart) {
            trendChart = new Chart(document.getElementById('monthlyTrendChart')?.getContext('2d'), {
                type: 'line',
                data: { labels: trendLabels, datasets: trendDatasets },
                options: { responsive: true, maintainAspectRatio: false },
            });
        } else {
            trendChart.data.labels = trendLabels;
            trendChart.data.datasets.forEach((ds, i) => { ds.data = trendDatasets[i].data; });
            trendChart.update();
        }

        // Dept late bar chart (horizontal)
        const deptLate = data.dept_late || [];
        const deptPayload = { labels: deptLate.map((d) => d.department), values: deptLate.map((d) => d.late_minutes) };
        if (!deptChart) {
            deptChart = new Chart(document.getElementById('deptLateChart')?.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: deptPayload.labels,
                    datasets: [{ label: 'Late Minutes', data: deptPayload.values, backgroundColor: colorSet.orange, borderRadius: 4 }],
                },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } },
            });
        } else {
            deptChart.data.labels = deptPayload.labels;
            deptChart.data.datasets[0].data = deptPayload.values;
            deptChart.update();
        }

        // Drilldown table - employees with >10 tardiness or undertime days
        const tbody = document.querySelector('#attDrillTable tbody');
        if (tbody) {
            const rows = data.drilldown || [];
            if (rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#94a3b8;">No employees exceed the threshold this period.</td></tr>`;
            } else {
                tbody.innerHTML = rows.map((e) => {
                    const tarBadge = e.tardiness_count > 10 ? badge(e.tardiness_count, 'danger') : e.tardiness_count;
                    const utBadge = e.undertime_count > 10 ? badge(e.undertime_count, 'warning') : e.undertime_count;
                    return `<tr>
                        <td>${e.emp_no ?? '-'}</td>
                        <td>${e.name ?? ''}</td>
                        <td>${e.department ?? ''}</td>
                        <td>${tarBadge}</td>
                        <td>${utBadge}</td>
                        <td><button class="hrm-btn att-notify-btn" style="font-size:0.8rem;padding:3px 10px;"
                                data-uid="${e.user_id}"
                                data-name="${e.name ?? ''}"
                                data-tardiness="${e.tardiness_count}"
                                data-undertime="${e.undertime_count}">Notify Dept Head</button></td>
                    </tr>`;
                }).join('');
            }
        }
    };

    const load = () => {
        const month = monthInput?.value || '';
        const dept = deptSelect?.value || '';
        const params = new URLSearchParams({ month, department: dept });

        fetch(`${root.dataset.url}?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then(render)
            .catch(() => Swal.fire('Error', 'Failed to load attendance data.', 'error'));
    };

    // Delegated click handler for Notify buttons
    root.addEventListener('click', (ev) => {
        const btn = ev.target.closest('.att-notify-btn');
        if (!btn) return;
        btn.disabled = true;
        fetch(root.dataset.notifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': root.dataset.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                user_id: btn.dataset.uid,
                tardiness_count: btn.dataset.tardiness,
                undertime_count: btn.dataset.undertime,
            }),
        })
            .then((r) => r.json())
            .then((res) => {
                if (res.success) Swal.fire('Sent', res.message, 'success');
                else Swal.fire('Error', res.message, 'error');
            })
            .catch(() => Swal.fire('Error', 'Request failed.', 'error'))
            .finally(() => { btn.disabled = false; });
    });

    filterBtn?.addEventListener('click', load);
    load();
};

// ── Enhancement 4: Payroll Overview ──────────────────────────────────────

const bindPayrollOverview = () => {
    const root = document.querySelector('.hrm-module');
    if (!root || !root.dataset.url?.includes('payroll-overview')) return;

    const csrf = root.dataset.csrf || '';
    const resolveBase = root.dataset.resolveUrl || '';
    let netPayChart = null;

    const statusColor = { draft: '#ffc107', computed: '#17a2b8', approved: '#28a745', locked: '#6610f2' };

    const render = (data) => {
        // Run status cards
        const runsEl = document.getElementById('payrollRunCards');
        if (runsEl) {
            if (!data.runs || data.runs.length === 0) {
                runsEl.innerHTML = `<p style="color:#94a3b8;font-style:italic;">No payroll runs found.</p>`;
            } else {
                runsEl.innerHTML = data.runs.map((r) => {
                    const color = statusColor[r.status] || '#6c757d';
                    return `<article class="hrm-summary-card" style="border-left:4px solid ${color}">
                        <p>${r.period ?? 'N/A'}</p>
                        <span class="status-chip" style="background:${color};color:#fff;font-size:0.75rem;">${(r.status || '').toUpperCase()}</span>
                        <br><small>${r.employee_count ?? 0} employees</small>
                        ${r.unresolved_exceptions > 0 ? `<br><small style="color:#dc3545;">&#x26a0; ${r.unresolved_exceptions} exception(s)</small>` : ''}
                        ${r.locked_at ? `<br><small style="color:#64748b;">Locked: ${r.locked_at}</small>` : ''}
                    </article>`;
                }).join('');
            }
        }

        // Exceptions table
        const tbody = document.querySelector('#payrollExceptionsTable tbody');
        if (tbody) {
            const excs = data.exceptions || [];
            if (excs.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#28a745;">&#x2713; No unresolved exceptions.</td></tr>`;
            } else {
                tbody.innerHTML = excs.map((e) => `<tr data-id="${e.id}">
                    <td>${e.period ?? ''}</td>
                    <td>${e.type ?? ''}</td>
                    <td>${e.description ?? ''}</td>
                    <td><button class="hrm-btn-secondary hrm-resolve-exception" type="button" data-id="${e.id}">Mark Resolved</button></td>
                </tr>`).join('');
            }
        }

        // Net pay chart
        if (data.dept_net_pay) {
            if (!netPayChart) {
                netPayChart = createBarChart('deptNetPayChart', 'Net Pay (PHP)', data.dept_net_pay, colorSet.green);
            } else {
                updateChart(netPayChart, data.dept_net_pay);
            }
        }
    };

    const load = () => {
        fetch(root.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then(render)
            .catch(() => Swal.fire('Error', 'Failed to load payroll overview.', 'error'));
    };

    document.addEventListener('click', async (evt) => {
        const btn = evt.target.closest('.hrm-resolve-exception');
        if (!btn) return;

        const id = btn.dataset.id;
        const confirm = await Swal.fire({
            title: 'Mark exception as resolved?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Resolve',
        });
        if (!confirm.isConfirmed) return;

        btn.disabled = true;
        try {
            const url = resolveBase.replace('__ID__', id);
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({}),
            });
            const json = await res.json();
            if (json.success) {
                await Swal.fire('Resolved', json.message, 'success');
                load();
            } else {
                await Swal.fire('Error', json.message || 'Could not resolve exception.', 'error');
                btn.disabled = false;
            }
        } catch {
            await Swal.fire('Error', 'Network error.', 'error');
            btn.disabled = false;
        }
    });

    load();
};

if (dashboardRoot) {
    initializeWorkforceCharts(dashboardRoot, window.hrManagerInitialData || {});
    bindAlertPanel(dashboardRoot);
}

if (moduleRoot && moduleRoot.dataset.module === 'reports' && moduleRoot !== dashboardRoot) {
    initializeWorkforceCharts(moduleRoot, window.hrManagerInitialData || {});
}

bindLeaveModule(moduleRoot);
if (moduleRoot?.dataset?.module === 'leave') {
    bindLeaveAnalytics(moduleRoot);
}
if (moduleRoot?.dataset?.module === 'records') {
    bindWorkforcePlanning(moduleRoot);
}
bindServiceMilestonesModule(moduleRoot);
bindFrontdeskModule(moduleRoot);
bindAuditModule(moduleRoot);
bindSimpleSuccessButtons();
bindAttendanceOverview();
bindPayrollOverview();
