const requestForm = document.getElementById('documentRequestForm');
const purposeField = document.getElementById('purpose');
const purposeCount = document.getElementById('purposeCount');
const purposeCounter = purposeCount ? purposeCount.parentElement : null;

function getSwal() {
    if (window.Swal && typeof window.Swal.fire === 'function') {
        return window.Swal;
    }

    return null;
}

function updatePurposeCounter() {
    if (!purposeField || !purposeCount) {
        return;
    }

    const currentLength = purposeField.value.length;
    purposeCount.textContent = String(currentLength);

    if (purposeCounter) {
        purposeCounter.classList.toggle('near-limit', currentLength >= 850);
    }
}

async function submitDocumentRequest(event) {
    event.preventDefault();

    if (!requestForm) {
        return;
    }

    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';
    const formData = new FormData(requestForm);
    const submitButton = document.getElementById('submitRequestBtn');
    const Swal = getSwal();

    if (Swal) {
        const confirmResult = await Swal.fire({
            title: 'Submit request?',
            text: 'This will send your document request to HR for processing.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, submit',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
        });

        if (!confirmResult.isConfirmed) {
            return;
        }
    } else if (!window.confirm('Submit this document request?')) {
        return;
    }

    try {
        if (submitButton) {
            submitButton.disabled = true;
        }

        const response = await fetch('/document-requests', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const validationErrors = payload && payload.errors ? Object.values(payload.errors).flat().join('\n') : '';
            throw new Error(validationErrors || payload.message || 'Request failed. Please check your input and try again.');
        }

        if (!payload.success) {
            throw new Error(payload.message || 'Unable to submit request.');
        }

        if (Swal) {
            await Swal.fire({
                title: 'Request submitted',
                text: payload.message || 'Your document request was submitted successfully.',
                icon: 'success',
                confirmButtonText: 'OK',
            });
        }

        window.location.reload();
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Submission failed.';

        if (Swal) {
            await Swal.fire({
                title: 'Submission failed',
                text: message,
                icon: 'error',
                confirmButtonText: 'Close',
            });
            return;
        }

        window.alert(message);
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
}

if (purposeField) {
    updatePurposeCounter();
    purposeField.addEventListener('input', updatePurposeCounter);
}

if (requestForm) {
    requestForm.addEventListener('submit', submitDocumentRequest);
}

if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
    window.jQuery(function initializeDocumentRequestsDataTable() {
        const tableElement = document.getElementById('documentRequestsTable');

        if (!tableElement) {
            return;
        }

        window.jQuery(tableElement).DataTable({
            pageLength: 10,
            order: [[0, 'desc']],
            responsive: true,
            language: {
                search: 'Search requests:',
                emptyTable: 'No document requests found.',
            },
        });
    });
}
