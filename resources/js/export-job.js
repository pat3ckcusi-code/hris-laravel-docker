import Swal from 'sweetalert2';

const POLL_INTERVAL_MS = 2000;

/**
 * Queue a background export job and show a SweetAlert progress dialog.
 *
 * @param {string} createUrl  POST endpoint that returns { job_id, status_url }
 * @param {object} params     Payload forwarded to the server (type + type-specific params)
 * @param {string} [label]    Optional label shown in the loading dialog
 */
window.startExport = function startExport(createUrl, params, label = 'Generating export…') {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    fetch(createUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
        },
        body: JSON.stringify(params),
    })
        .then((res) => {
            if (!res.ok) {
                return res.json().then((body) => {
                    throw new Error(body.message || `Server error ${res.status}`);
                });
            }
            return res.json();
        })
        .then(({ status_url }) => {
            let pollTimer;

            Swal.fire({
                title: 'Please wait',
                html: label,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen() {
                    Swal.showLoading();

                    pollTimer = setInterval(() => {
                        fetch(status_url, { headers: { Accept: 'application/json' } })
                            .then((r) => r.json())
                            .then(({ status, download_url, filename, error }) => {
                                if (status === 'completed') {
                                    clearInterval(pollTimer);
                                    Swal.close();

                                    const a = document.createElement('a');
                                    a.href = download_url;
                                    a.download = filename ?? 'export';
                                    document.body.appendChild(a);
                                    a.click();
                                    document.body.removeChild(a);
                                } else if (status === 'failed') {
                                    clearInterval(pollTimer);
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Export Failed',
                                        text: error ?? 'Something went wrong. Please try again.',
                                        confirmButtonColor: '#3b82f6',
                                    });
                                }
                                // 'pending' / 'processing' — keep polling
                            })
                            .catch(() => {
                                clearInterval(pollTimer);
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: 'Could not check export status. Please try again.',
                                    confirmButtonColor: '#3b82f6',
                                });
                            });
                    }, POLL_INTERVAL_MS);
                },
                willClose() {
                    clearInterval(pollTimer);
                },
            });
        })
        .catch((err) => {
            Swal.fire({
                icon: 'error',
                title: 'Export Error',
                text: err.message,
                confirmButtonColor: '#3b82f6',
            });
        });
};
