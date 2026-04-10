const appRoot = document.getElementById('frontDeskApp');

if (appRoot) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const urls = {
        fetch: appRoot.dataset.fetchUrl,
        accept: appRoot.dataset.acceptUrl,
        reject: appRoot.dataset.rejectUrl,
        complete: appRoot.dataset.completeUrl,
        printBase: appRoot.dataset.printBaseUrl,
        printReport: appRoot.dataset.printReportUrl,
    };

    const state = {
        pendingTable: null,
        approvedTable: null,
    };

    const filterDate = document.getElementById('filterDate');
    const filterMonth = document.getElementById('filterMonth');
    const filterDocumentType = document.getElementById('filterDocumentType');
    const filterStatus = document.getElementById('filterStatus');

    function getSwal() {
        return window.Swal && typeof window.Swal.fire === 'function' ? window.Swal : null;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function filtersToParams(extra = {}) {
        const params = new URLSearchParams();
        const values = {
            date: filterDate?.value || '',
            month: filterMonth?.value || '',
            document_type: filterDocumentType?.value || '',
            status: filterStatus?.value || '',
            ...extra,
        };

        Object.entries(values).forEach(([key, value]) => {
            if (value) params.set(key, value);
        });

        return params;
    }

    function badgeClass(status) {
        switch (status) {
            case 'Requested': return 'badge-requested';
            case 'Accepted': return 'badge-approved';
            case 'Completed': return 'badge-completed';
            case 'Rejected': return 'badge-rejected';
            default: return 'badge-default';
        }
    }

    function pendingActionHtml(row) {
        if (row.status !== 'Requested') {
            return '<span class="muted">No action</span>';
        }

        return `
            <div class="fd-actions">
                <button type="button" class="fd-action-btn fd-accept-btn" data-action="accept" data-id="${row.id}"><i class="fas fa-check"></i> Accept</button>
                <button type="button" class="fd-action-btn fd-reject-btn" data-action="reject" data-id="${row.id}"><i class="fas fa-times"></i> Reject</button>
            </div>
        `;
    }

    function approvedActionHtml(row) {
        if (row.status !== 'Accepted') {
            return '<span class="muted">No action</span>';
        }

        return `
            <div class="fd-actions">
                <button type="button" class="fd-action-btn fd-print-btn" data-action="print" data-id="${row.id}"><i class="fas fa-print"></i> Print</button>
                <button type="button" class="fd-action-btn fd-complete-btn" data-action="complete" data-id="${row.id}"><i class="fas fa-box"></i> Mark as Complete</button>
            </div>
        `;
    }

    function mapRow(row, actionHtml) {
        return [
            escapeHtml(row.emp_no),
            escapeHtml(row.employee_name),
            escapeHtml(row.department),
            escapeHtml(row.document_type),
            escapeHtml(row.purpose),
            escapeHtml(row.requested_on),
            `<span class="request-badge ${badgeClass(row.status)}">${escapeHtml(row.status)}</span>`,
            escapeHtml(row.remarks),
            actionHtml,
        ];
    }

    function initDataTable(selector) {
        if (!window.jQuery?.fn?.DataTable) {
            return null;
        }

        return window.jQuery(selector).DataTable({
            responsive: true,
            pageLength: 8,
            order: [[5, 'desc']],
            language: {
                search: 'Search:',
                emptyTable: 'No requests found.',
            },
        });
    }

    function rebuildTable(table, rows, actionBuilder) {
        if (!table) return;
        table.clear();
        rows.forEach((row) => {
            table.row.add(mapRow(row, actionBuilder(row)));
        });
        table.draw();
    }

    function updateSummary(summary) {
        document.getElementById('summaryTotal').textContent = String(summary.total || 0);
        document.getElementById('summaryPending').textContent = String(summary.pending || 0);
        document.getElementById('summaryApproved').textContent = String(summary.approved || 0);
        document.getElementById('summaryCompleted').textContent = String(summary.completed || 0);
    }

    async function loadData() {
        const response = await fetch(`${urls.fetch}?${filtersToParams().toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error('Failed to load requests.');

        const payload = await response.json();
        updateSummary(payload.summary || {});
        rebuildTable(state.pendingTable, payload.pending || [], pendingActionHtml);
        rebuildTable(state.approvedTable, payload.approved || [], approvedActionHtml);
    }

    async function postAction(url, requestId, successText) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ request_id: requestId }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'Action failed.');

        const Swal = getSwal();
        if (Swal) {
            await Swal.fire({ icon: 'success', title: 'Success', text: payload.message || successText });
        }

        await loadData();
    }

    async function confirmAction(title, text) {
        const Swal = getSwal();
        if (!Swal) {
            return window.confirm(text);
        }

        const result = await Swal.fire({
            icon: 'question',
            title,
            text,
            showCancelButton: true,
            confirmButtonText: 'Confirm',
            cancelButtonText: 'Cancel',
        });

        return result.isConfirmed;
    }

    async function printSingle(requestId) {
        const allowed = await confirmAction('Print request?', 'Generate print-ready document now?');
        if (!allowed) return;

        window.open(`${urls.printBase}/${requestId}`, '_blank');
    }

    async function printBatch(scope) {
        const allowed = await confirmAction('Print report?', 'Generate printable report for current filters?');
        if (!allowed) return;

        const response = await fetch(urls.printReport, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                Accept: 'text/html',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: filtersToParams({ scope }).toString(),
        });

        if (!response.ok) throw new Error('Failed to print report.');

        const html = await response.text();
        const popup = window.open('', '_blank');
        if (!popup) throw new Error('Popup blocked while opening print preview.');
        popup.document.open();
        popup.document.write(html);
        popup.document.close();
    }

    async function handleAction(event) {
        const button = event.target.closest('[data-action]');
        if (!button) return;

        const id = Number(button.dataset.id || 0);
        if (!id) return;

        try {
            switch (button.dataset.action) {
                case 'accept': {
                    const allowed = await confirmAction('Accept request?', 'This request will move to Approved table and employee will be emailed.');
                    if (!allowed) return;
                    await postAction(urls.accept, id, 'Request accepted.');
                    break;
                }
                case 'reject': {
                    const allowed = await confirmAction('Reject request?', 'This request will be rejected and employee will be emailed.');
                    if (!allowed) return;
                    await postAction(urls.reject, id, 'Request rejected.');
                    break;
                }
                case 'complete': {
                    const allowed = await confirmAction('Mark as complete?', 'Employee will be notified that the document is ready for pick-up.');
                    if (!allowed) return;
                    await postAction(urls.complete, id, 'Request marked as completed.');
                    break;
                }
                case 'print':
                    await printSingle(id);
                    break;
                default:
                    break;
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Action failed.';
            const Swal = getSwal();
            if (Swal) {
                await Swal.fire({ icon: 'error', title: 'Error', text: message });
                return;
            }
            window.alert(message);
        }
    }

    async function init() {
        state.pendingTable = initDataTable('#pendingRequestsTable');
        state.approvedTable = initDataTable('#approvedRequestsTable');

        document.getElementById('applyFiltersBtn')?.addEventListener('click', loadData);
        document.getElementById('resetFiltersBtn')?.addEventListener('click', async () => {
            if (filterDate) filterDate.value = '';
            if (filterMonth) filterMonth.value = '';
            if (filterDocumentType) filterDocumentType.value = '';
            if (filterStatus) filterStatus.value = '';
            await loadData();
        });

        document.querySelectorAll('[data-print-scope]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    await printBatch(btn.dataset.printScope || 'all');
                } catch (error) {
                    const message = error instanceof Error ? error.message : 'Print failed.';
                    const Swal = getSwal();
                    if (Swal) {
                        await Swal.fire({ icon: 'error', title: 'Error', text: message });
                        return;
                    }
                    window.alert(message);
                }
            });
        });

        document.addEventListener('click', handleAction);
        await loadData();
    }

    init();
}
