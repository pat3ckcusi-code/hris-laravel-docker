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
            case 'Requested':  return 'badge-requested';
            case 'Processed':  return 'badge-approved';
            case 'Released':   return 'badge-completed';
            case 'Rejected':   return 'badge-rejected';
            default:           return 'badge-default';
        }
    }

    function pendingActionHtml(row) {
        if (row.status !== 'Requested') {
            return '<span class="muted">No action</span>';
        }

        return `
            <div class="fd-actions">
                <button type="button" class="fd-action-btn fd-print-btn" data-action="print" data-id="${row.id}"><i class="fas fa-print"></i> Print</button>
                <button type="button" class="fd-action-btn fd-accept-btn" data-action="process" data-id="${row.id}"><i class="fas fa-check"></i> Process</button>
            </div>
        `;
    }

    function approvedActionHtml(row) {
        if (row.status === 'Released') {
            return `
                <div class="fd-actions">
                    <button type="button" class="fd-action-btn fd-print-btn" data-action="print" data-id="${row.id}"><i class="fas fa-print"></i> Print</button>
                    <span class="muted">Released</span>
                </div>
            `;
        }

        if (row.status !== 'Processed') {
            return '<span class="muted">No action</span>';
        }

        return `
            <div class="fd-actions">
                <button type="button" class="fd-action-btn fd-print-btn" data-action="print" data-id="${row.id}"><i class="fas fa-print"></i> Print</button>
                <button type="button" class="fd-action-btn fd-complete-btn" data-action="release" data-id="${row.id}"><i class="fas fa-box-open"></i> Release</button>
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
        const elements = {
            summaryTotal: document.getElementById('summaryTotal'),
            summaryPending: document.getElementById('summaryPending'),
            summaryApproved: document.getElementById('summaryApproved'),
            summaryCompleted: document.getElementById('summaryCompleted'),
        };

        if (elements.summaryTotal) {
            elements.summaryTotal.textContent = String(summary.total || 0);
        }
        if (elements.summaryPending) {
            elements.summaryPending.textContent = String(summary.pending || 0);
        }
        if (elements.summaryApproved) {
            elements.summaryApproved.textContent = String(summary.approved || 0);
        }
        if (elements.summaryCompleted) {
            elements.summaryCompleted.textContent = String(summary.completed || 0);
        }
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
                case 'process': {
                    const allowed = await confirmAction('Process request?', 'This request will move to Approved table and employee will be notified.');
                    if (!allowed) return;
                    await postAction(urls.accept, id, 'Request processed.');
                    break;
                }
                case 'reject': {
                    const allowed = await confirmAction('Reject request?', 'This request will be rejected and employee will be emailed.');
                    if (!allowed) return;
                    await postAction(urls.reject, id, 'Request rejected.');
                    break;
                }
                case 'release': {
                    const allowed = await confirmAction('Release document?', 'Employee will be notified that the document is ready for pick-up.');
                    if (!allowed) return;
                    await postAction(urls.complete, id, 'Document released.');
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

// Global functions for server-rendered Pending Requests view
window.getSwal = function() {
    return window.Swal && typeof window.Swal.fire === 'function' ? window.Swal : null;
}

window.getCsrfToken = function() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

window.acceptRequest = async function(requestId) {
    const Swal = window.getSwal();
    const confirmed = await (Swal
        ? Swal.fire({
            icon: 'question',
            title: 'Confirm Accept',
            text: 'Are you sure you want to accept this request?',
            showCancelButton: true,
            confirmButtonText: 'Accept',
            cancelButtonText: 'Cancel',
        })
        : { isConfirmed: window.confirm('Accept this request?') });

    if (!confirmed.isConfirmed) return;

    try {
        const response = await fetch(
            '/dashboard/employee/front-desk/accept',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.getCsrfToken(),
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ request_id: requestId }),
            }
        );

        if (!response.ok) {
            throw new Error(`Failed to accept request: HTTP ${response.status}`);
        }

        const data = await response.json();

        if (Swal) {
            await Swal.fire({
                icon: 'success',
                title: 'Success',
                text: data.message || 'Request accepted successfully.',
            });
        } else {
            alert(data.message || 'Request accepted successfully.');
        }

        // Reload the page to reflect changes
        window.location.reload();
    } catch (error) {
        const message = error instanceof Error ? error.message : 'An error occurred.';
        if (Swal) {
            await Swal.fire({
                icon: 'error',
                title: 'Error',
                text: message,
            });
        } else {
            alert(message);
        }
    }
}

window.rejectRequest = async function(requestId) {
    const Swal = window.getSwal();

    if (!Swal) {
        const reason = window.prompt('Please provide a reason for rejection:');
        if (!reason || reason.trim() === '') {
            alert('Rejection reason is required.');
            return;
        }
        window.submitReject(requestId, reason);
        return;
    }

    const result = await Swal.fire({
        icon: 'question',
        title: 'Reject Request',
        text: 'Please provide a reason for rejection:',
        input: 'textarea',
        inputPlaceholder: 'Enter rejection reason...',
        inputValidator: (value) => {
            if (!value || value.trim() === '') {
                return 'Rejection reason is required.';
            }
        },
        showCancelButton: true,
        confirmButtonText: 'Reject',
        cancelButtonText: 'Cancel',
    });

    if (result.isConfirmed) {
        await window.submitReject(requestId, result.value);
    }
}

window.submitReject = async function(requestId, reason) {
    const Swal = window.getSwal();

    try {
        const response = await fetch(
            '/dashboard/employee/front-desk/reject',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.getCsrfToken(),
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    request_id: requestId,
                    remarks: reason,
                }),
            }
        );

        if (!response.ok) {
            throw new Error(`Failed to reject request: HTTP ${response.status}`);
        }

        const data = await response.json();

        if (Swal) {
            await Swal.fire({
                icon: 'success',
                title: 'Success',
                text: data.message || 'Request rejected successfully.',
            });
        } else {
            alert(data.message || 'Request rejected successfully.');
        }

        // Reload the page to reflect changes
        window.location.reload();
    } catch (error) {
        const message = error instanceof Error ? error.message : 'An error occurred.';
        if (Swal) {
            await Swal.fire({
                icon: 'error',
                title: 'Error',
                text: message,
            });
        } else {
            alert(message);
        }
    }
}
