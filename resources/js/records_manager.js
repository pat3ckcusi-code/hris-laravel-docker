function serializeFormData(form) {
    const fd = new FormData(form);
    return fd;
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.record-form').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const fd = serializeFormData(form);
            const dateHired = fd.get('date_hired');
            if (!dateHired) {
                Swal.fire({ icon: 'error', title: 'Validation', text: 'Date Hired is required.' });
                return;
            }

            let method = (fd.get('_method') || form.method || 'POST').toString().toUpperCase();
            let url = form.action;

            // If method is PUT/DELETE, send POST with _method override
            const useMethod = (method === 'GET') ? 'GET' : 'POST';
            if (method !== 'POST' && method !== 'GET') {
                fd.set('_method', method);
            }

            // Close any parent <dialog> before opening SweetAlert - otherwise
            // Swal renders behind the dialog's top-layer stacking context.
            const dialog = form.closest('dialog');
            if (dialog && dialog.open) dialog.close();

            Swal.fire({ title: form.dataset.processingTitle || 'Processing', text: form.dataset.processingText || '', didOpen: () => { Swal.showLoading(); } });

            try {
                const res = await fetch(url, {
                    method: useMethod,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                });

                const payload = await res.json();
                Swal.close();

                if (!res.ok) {
                    Swal.fire({ icon: 'error', title: 'Error', text: payload.message || payload.error || 'Failed to save.' })
                        .then(() => { if (dialog && typeof dialog.showModal === 'function') dialog.showModal(); });
                    return;
                }

                Swal.fire({ icon: 'success', title: 'Success', text: payload.message || 'Saved successfully.' }).then(() => {
                    window.location.reload();
                });
            } catch (err) {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' })
                    .then(() => { if (dialog && typeof dialog.showModal === 'function') dialog.showModal(); });
            }
        });
    });
});
