const appRoot = document.getElementById('frontDeskApp');

if (appRoot) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const pendingTableId = '#pendingRequestsTable';
    const approvedTableId = '#approvedRequestsTable';

    const filterDate = document.getElementById('filterDate');
    const filterMonth = document.getElementById('filterMonth');
    const filterDocumentType = document.getElementById('filterDocumentType');
    const filterStatus = document.getElementById('filterStatus');
    const applyFiltersBtn = document.getElementById('applyFiltersBtn');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');

    function getSwal() {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            return window.Swal;
        }

        return null;
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
        const filters = {
            date: filterDate?.value || '',
            month: filterMonth?.value || '',
            document_type: filterDocumentType?.value || '',
            status: filterStatus?.value || '',
            ...extra,
        };

        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params.set(key, value);
            }
        });

        return params;
    }

    function badgeClass(status) {
        switch (status) {
            case 'Requested':
                return 'badge-requested';
            case 'Pending':
                return 'badge-pending';
            case 'Approved':
                return 'badge-approved';
            case 'Completed':
                return 'badge-completed';
            case 'Rejected':
                return 'badge-rejected';
            default:
                return 'badge-default';
        }
    }

    function rowMarkup(row) {
        return `
            <tr>
                <td>${escapeHtml(row.emp_no)}</td>
                <td>${escapeHtml(row.employee_name)}</td>
                <td>${escapeHtml(row.department)}</td>
                <td>${escapeHtml(row.document_type)}</td>
                <td>${escapeHtml(row.purpose)}</td>
                <td>${escapeHtml(row.requested_on)}</td>
                <td><span class="request-badge ${badgeClass(row.status)}">${escapeHtml(row.status)}</span></td>
                <td>${escapeHtml(row.remarks)}</td>
                <td>
                    <div class="action-icons">
                        <button type="button" class="action-icon-btn" data-action="print" data-request-id="${row.id}" title="Print request">
                            <i class="fas fa-print"></i>
                        </button>
                        <button type="button" class="action-icon-btn" data-action="update" data-request-id="${row.id}" data-current-status="${escapeHtml(row.status)}" data-current-remarks="${escapeHtml(row.remarks === '-' ? '' : row.remarks)}" title="Update request">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }

    function renderTable(tableSelector, rows) {
        const tableElement = document.querySelector(tableSelector);

        if (!tableElement) {
            return;
        }

        if (window.jQuery?.fn?.DataTable && window.jQuery.fn.DataTable.isDataTable(tableElement)) {
            window.jQuery(tableElement).DataTable().destroy();
        }

        const tbody = tableElement.querySelector('tbody');
        tbody.innerHTML = rows.map(rowMarkup).join('');

        if (window.jQuery?.fn?.DataTable) {
            window.jQuery(tableElement).DataTable({
                responsive: true,
                pageLength: 8,
                order: [[5, 'desc']],
                language: {
                    search: 'Search:',
                    emptyTable: 'No requests found.',
                },
            });
        }
    }

    function updateSummary(summary) {
        document.getElementById('summaryTotal').textContent = String(summary.total || 0);
        document.getElementById('summaryPending').textContent = String(summary.pending || 0);
        document.getElementById('summaryApproved').textContent = String(summary.approved || 0);
        document.getElementById('summaryCompleted').textContent = String(summary.completed || 0);
    }

    async function loadRequests() {
        const response = await fetch(`${appRoot.dataset.fetchUrl}?${filtersToParams().toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Failed to load document requests.');
        }

        const payload = await response.json();
        updateSummary(payload.summary || {});
        renderTable(pendingTableId, payload.pending || []);
        renderTable(approvedTableId, payload.approved || []);
    }

    async function showStatusModal(button) {
        const Swal = getSwal();
        const currentStatus = button.dataset.currentStatus || 'Requested';
        const currentRemarks = button.dataset.currentRemarks || '';

        if (!Swal) {
            return null;
        }

        return Swal.fire({
            title: 'Update request status',
            html: `
                <label style="display:block; text-align:left; margin-bottom:8px; font-weight:600;">Status</label>
                <select id="swalStatus" class="swal2-input">
                    <option value="Requested" ${currentStatus === 'Requested' ? 'selected' : ''}>Requested</option>
                    <option value="Pending" ${currentStatus === 'Pending' ? 'selected' : ''}>Pending</option>
                    <option value="Approved" ${currentStatus === 'Approved' ? 'selected' : ''}>Approved</option>
                    <option value="Completed" ${currentStatus === 'Completed' ? 'selected' : ''}>Completed</option>
                    <option value="Rejected" ${currentStatus === 'Rejected' ? 'selected' : ''}>Rejected</option>
                </select>
                <label style="display:block; text-align:left; margin:12px 0 8px; font-weight:600;">Remarks</label>
                <textarea id="swalRemarks" class="swal2-textarea" placeholder="Add front desk remarks">${escapeHtml(currentRemarks)}</textarea>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Save update',
            preConfirm: () => {
                const status = document.getElementById('swalStatus')?.value || '';
                const remarks = document.getElementById('swalRemarks')?.value || '';

                if (!status) {
                    Swal.showValidationMessage('Status is required.');
                    return null;
                }

                return { status, remarks };
            },
        });
    }

    async function updateStatus(button) {
        const Swal = getSwal();

        if (!Swal) {
            return;
        }

        const result = await showStatusModal(button);
        if (!result || !result.isConfirmed || !result.value) {
            return;
        }

        const response = await fetch(appRoot.dataset.updateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                request_id: Number(button.dataset.requestId),
                status: result.value.status,
                remarks: result.value.remarks,
            }),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || 'Failed to update the request.');
        }

        await Swal.fire({
            icon: 'success',
            title: 'Updated',
            text: payload.message || 'Request updated successfully.',
        });

        await loadRequests();
    }

    async function printReport(extra = {}) {
        const Swal = getSwal();
        const confirmResult = Swal
            ? await Swal.fire({
                icon: 'question',
                title: 'Print report?',
                text: 'A printable report will be generated for the current selection.',
                showCancelButton: true,
                confirmButtonText: 'Continue',
            })
            : { isConfirmed: window.confirm('Print this report?') };

        if (!confirmResult.isConfirmed) {
            return;
        }

        const response = await fetch(appRoot.dataset.printUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                Accept: 'text/html',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: filtersToParams(extra).toString(),
        });

        if (!response.ok) {
            throw new Error('Failed to generate printable report.');
        }

        const html = await response.text();
        const popup = window.open('', '_blank');

        if (!popup) {
            throw new Error('Unable to open print preview. Check popup blocker settings.');
        }

        popup.document.open();
        popup.document.write(html);
        popup.document.close();

        if (Swal) {
            await Swal.fire({
                icon: 'success',
                title: 'Report ready',
                text: 'The printable report opened in a new tab.',
            });
        }
    }

    async function handleActionClick(event) {
        const button = event.target.closest('[data-action]');

        if (!button) {
            return;
        }

        try {
            if (button.dataset.action === 'update') {
                await updateStatus(button);
            }

            if (button.dataset.action === 'print') {
                await printReport({ request_id: button.dataset.requestId });
            }
        } catch (error) {
            const Swal = getSwal();
            const message = error instanceof Error ? error.message : 'Action failed.';

            if (Swal) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Action failed',
                    text: message,
                });
                return;
            }

            window.alert(message);
        }
    }

    async function guardedLoadRequests() {
        try {
            await loadRequests();
        } catch (error) {
            const Swal = getSwal();
            const message = error instanceof Error ? error.message : 'Unable to load requests.';

            if (Swal) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Load failed',
                    text: message,
                });
                return;
            }

            window.alert(message);
        }
    }

    applyFiltersBtn?.addEventListener('click', guardedLoadRequests);
    resetFiltersBtn?.addEventListener('click', async () => {
        if (filterDate) filterDate.value = '';
        if (filterMonth) filterMonth.value = '';
        if (filterDocumentType) filterDocumentType.value = '';
        if (filterStatus) filterStatus.value = '';
        await guardedLoadRequests();
    });

    document.querySelectorAll('[data-print-scope]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await printReport({ scope: button.dataset.printScope || 'all' });
            } catch (error) {
                const Swal = getSwal();
                const message = error instanceof Error ? error.message : 'Unable to print report.';

                if (Swal) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Print failed',
                        text: message,
                    });
                    return;
                }

                window.alert(message);
            }
        });
    });

    document.addEventListener('click', handleActionClick);
    guardedLoadRequests();
}
