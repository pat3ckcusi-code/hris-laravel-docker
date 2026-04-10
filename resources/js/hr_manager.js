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
        totalWorkforceChart: createBarChart(
            'totalWorkforceChart',
            'Total Workforce',
            initialData.workforce_per_department || initialData.total_workforce,
            colorSet.orange,
            {
                onClick: (evt, elements) => {
                    if (elements.length > 0) {
                        const idx = elements[0].index;
                        const department = charts.totalWorkforceChart.data.labels[idx];
                        fetchEmployees('department', department, `Employees in ${department}`);
                    }
                },
                scales: {
                    x: { display: false },
                    y: { title: { display: true, text: 'Total Employees' } },
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
        if (charts.genderChart) {
            charts.genderChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    fetchEmployees('gender', label, `Employees: ${label}`);
                }
            };
            charts.genderChart.update();
        }

        if (charts.employmentStatusChart) {
            charts.employmentStatusChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    // filter by employee_type on click
                    fetchEmployees('employee_type', label, `Employees: ${label}`);
                }
            };
            charts.employmentStatusChart.update();
        }

        if (charts.ageGroupChart) {
            charts.ageGroupChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    fetchEmployees('age_group', label, `Employees: ${label}`);
                }
            };
            charts.ageGroupChart.update();
        }

        if (charts.lengthOfServiceChart) {
            charts.lengthOfServiceChart.options.onClick = function (evt, elements) {
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    const label = this.data.labels[idx];
                    fetchEmployees('length_of_service', label, `Employees: ${label}`);
                }
            };
            charts.lengthOfServiceChart.update();
        }
    };

    const chartUrl = root.dataset.chartUrl || '';
    const filter = document.getElementById('departmentFilter');

    const refreshCharts = (payload) => {
        // totalWorkforceChart uses workforce_per_department labels/values
        updateChart(charts.totalWorkforceChart, payload.workforce_per_department || payload.total_workforce);
        updateChart(charts.genderChart, payload.gender_distribution);
        updateChart(charts.employmentStatusChart, payload.employment_status);
        updateChart(charts.ageGroupChart, payload.age_group_distribution);
        updateChart(charts.lengthOfServiceChart, payload.length_of_service);

        // Re-attach click handlers after chart updates
        attachChartClickHandlers();
    };

    // Fetch employees for a given filter and render popup
    const fetchEmployees = async (key, value, title = 'Employees') => {
        try {
            const params = new URLSearchParams();
            if (key && value !== undefined && value !== null) {
                params.append(key, String(value));
            }

            const url = `${root.dataset.chartUrl.replace(/chart-data$/, 'employees/filter')}?${params.toString()}`;
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error('Failed to fetch employees.');
            const data = await res.json();
            renderEmployeePopup(title, data || []);
        } catch (err) {
            await Swal.fire('Error', 'Failed to load employee list.', 'error');
        }
    };

    const renderEmployeePopup = (title, employees) => {
        const tableHead = `
            <thead>
                <tr>
                    <th>Employee No</th>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Gender</th>
                    <th>Age</th>
                    <th>Employee Type</th>
                    <th>Date Hired</th>
                </tr>
            </thead>`;

        const bodyRows = employees
            .map((emp) => `
                <tr>
                    <td>${emp.emp_no ?? ''}</td>
                    <td>${emp.name ?? ''}</td>
                    <td>${emp.position ?? ''}</td>
                    <td>${emp.gender ?? ''}</td>
                    <td>${emp.age ?? ''}</td>
                    <td>${emp.employee_type ?? ''}</td>
                    <td>${emp.date_hired ?? ''}</td>
                </tr>`)
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

    const loadChartData = async (department) => {
        try {
            const query = department ? `?department=${encodeURIComponent(department)}` : '';
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
        filter.addEventListener('change', (event) => {
            loadChartData(event.target.value);
        });
    }

    // initial attach
    attachChartClickHandlers();
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

const bindRecordsModule = (root) => {
    if (!root || root.dataset.module !== 'records') return;

    const tableBody = document.querySelector('#recordsTable tbody');
    const search = document.getElementById('recordsSearch');
    const department = document.getElementById('recordsDepartment');
    const status = document.getElementById('recordsStatus');
    const filterBtn = document.getElementById('recordsFilterBtn');
    const paginationRoot = document.getElementById('recordsPagination');
    const csrf = root.dataset.csrf || '';

    const renderRows = (rows) => {
        if (!tableBody) return;

        tableBody.innerHTML = rows
            .map(
                (row) => `
                    <tr data-id="${row.id}">
                        <td>${row.emp_no ?? ''}</td>
                        <td>${row.name ?? ''}</td>
                        <td>${row.department ?? ''}</td>
                        <td>${row.position ?? ''}</td>
                        <td><span class="status-chip">${row.employment_status ?? ''}</span></td>
                        <td>${row.history ?? ''}</td>
                        <td>
                            <button class="hrm-btn-secondary hrm-record-edit" type="button">Edit</button>
                            <button class="hrm-btn-secondary hrm-record-update" type="button">Update</button>
                            <button class="hrm-btn-secondary hrm-record-compliance" type="button">Generate Compliance Report</button>
                        </td>
                    </tr>
                `
            )
            .join('');
    };

    const fetchRows = async (page = 1) => {
        const params = new URLSearchParams({
            search: search?.value || '',
            department: department?.value || '',
            status: status?.value || '',
            page: String(page),
        });

        try {
            const response = await fetch(`${root.dataset.url}?${params.toString()}`);
            if (!response.ok) throw new Error('Records load failed.');

            const data = await response.json();
            renderRows(data.rows || []);
            renderPagination(paginationRoot, data.pagination, fetchRows);
        } catch (error) {
            await Swal.fire('Error', 'Failed to load employee profiles.', 'error');
        }
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

        let action = null;
        let title = 'Action completed';

        if (button.classList.contains('hrm-record-edit')) {
            action = 'edit';
            title = 'Edit action logged';
        }

        if (button.classList.contains('hrm-record-update')) {
            action = 'update';
            title = 'Update action logged';
        }

        if (button.classList.contains('hrm-record-compliance')) {
            action = 'compliance-report';
            title = 'Compliance action logged';
        }

        if (!action) return;

        try {
            const actionUrl = (root.dataset.actionUrl || '').replace('__ID__', id);
            const response = await fetch(actionUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ action }),
            });

            if (!response.ok) throw new Error('Failed to log records action.');
            await Swal.fire('Success', title, 'success');
        } catch (error) {
            await Swal.fire('Error', 'Failed to process records action.', 'error');
        }
    });
};

const bindLeaveModule = (root) => {
    if (!root || root.dataset.module !== 'leave') return;

    const tableBody = document.querySelector('#leaveTable tbody');
    const table = document.getElementById('leaveTable');
    const department = document.getElementById('leaveDepartment');
    const status = document.getElementById('leaveStatus');
    const filterBtn = document.getElementById('leaveFilterBtn');
    const paginationRoot = document.getElementById('leavePagination');
    const csrf = root.dataset.csrf || '';

    const initialChart = JSON.parse(table?.dataset.initialChart || '{"labels":[],"values":[]}');
    const leaveChart = createBarChart('leaveUsageChart', 'Leave Requests', initialChart, colorSet.orange);

    const renderRows = (rows) => {
        if (!tableBody) return;

        tableBody.innerHTML = rows
            .map(
                (row) => `
                    <tr data-id="${row.id}">
                        <td>${row.employee_name ?? ''}</td>
                        <td>${row.department ?? ''}</td>
                        <td>${row.leave_type ?? ''}</td>
                        <td>${row.period ?? ''}</td>
                        <td>${row.days ?? ''}</td>
                        <td><span class="status-chip status-${row.status}">${(row.status || '').toUpperCase()}</span></td>
                        <td>
                            <button class="hrm-btn-secondary hrm-leave-approve" type="button">Approve</button>
                            <button class="hrm-btn-secondary hrm-leave-reject" type="button">Reject</button>
                        </td>
                    </tr>
                `
            )
            .join('');
    };

    const fetchRows = async (page = 1) => {
        const params = new URLSearchParams({
            department: department?.value || '',
            status: status?.value || 'pending',
            page: String(page),
        });

        try {
            const response = await fetch(`${root.dataset.url}?${params.toString()}`);
            if (!response.ok) throw new Error('Leave data load failed.');

            const data = await response.json();
            renderRows(data.rows || []);
            renderPagination(paginationRoot, data.pagination, fetchRows);
            updateChart(leaveChart, data.chart || { labels: [], values: [] });
        } catch (error) {
            await Swal.fire('Error', 'Failed to load leave requests.', 'error');
        }
    };

    const submitAction = async (id, action) => {
        const actionUrl = (root.dataset.actionUrl || '').replace('__ID__', id);
        const confirm = await Swal.fire({
            title: `${action === 'approve' ? 'Approve' : 'Reject'} leave request?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Confirm',
        });

        if (!confirm.isConfirmed) return;

        const payload = { action };
        if (action === 'reject') {
            const notes = await Swal.fire({
                title: 'Rejection Remarks',
                input: 'text',
                inputPlaceholder: 'Optional remarks',
                showCancelButton: true,
            });
            if (notes.isDismissed) return;
            payload.remarks = notes.value || '';
        }

        try {
            const response = await fetch(actionUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) throw new Error('Action failed.');

            await Swal.fire('Success', 'Leave request updated.', 'success');
            fetchRows();
        } catch (error) {
            await Swal.fire('Error', 'Failed to update leave request.', 'error');
        }
    };

    filterBtn?.addEventListener('click', () => fetchRows(1));

    const initialPagination = JSON.parse(root.dataset.pagination || '{"current_page":1,"last_page":1}');
    renderPagination(paginationRoot, initialPagination, fetchRows);

    tableBody?.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        const row = event.target.closest('tr');
        if (!button || !row) return;

        const id = row.dataset.id;
        if (!id) return;

        if (button.classList.contains('hrm-leave-approve')) {
            submitAction(id, 'approve');
        }

        if (button.classList.contains('hrm-leave-reject')) {
            submitAction(id, 'reject');
        }
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

    const fetchRows = async () => {
        const params = new URLSearchParams({
            user: user?.value || '',
            date: date?.value || '',
            action: action?.value || '',
        });

        try {
            const response = await fetch(`${root.dataset.url}?${params.toString()}`);
            if (!response.ok) throw new Error('Audit data load failed.');

            const data = await response.json();
            renderRows(data.rows || []);
        } catch (error) {
            await Swal.fire('Error', 'Failed to load audit logs.', 'error');
        }
    };

    filterBtn?.addEventListener('click', fetchRows);
};

const bindSimpleSuccessButtons = () => {
    document.querySelectorAll('.hrm-alert-success').forEach((button) => {
        button.addEventListener('click', async () => {
            await Swal.fire('Saved', 'Changes have been captured in this scaffold.', 'success');
        });
    });
};

if (dashboardRoot) {
    initializeWorkforceCharts(dashboardRoot, window.hrManagerInitialData || {});
}

if (moduleRoot && moduleRoot.dataset.module === 'reports' && moduleRoot !== dashboardRoot) {
    initializeWorkforceCharts(moduleRoot, window.hrManagerInitialData || {});
}

bindRecordsModule(moduleRoot);
bindLeaveModule(moduleRoot);
bindFrontdeskModule(moduleRoot);
bindAuditModule(moduleRoot);
bindSimpleSuccessButtons();
