/**
 * layout.js — Global scripts loaded on every dashboard page via Vite.
 *
 * Provides:
 *  • showProcessingOverlayFallback()  — full-screen spinner when SweetAlert2 isn't available
 *  • [data-uppercase-input] handler   — uppercases inputs on the fly
 *  • [data-processing-submit] handler — disable-button + overlay on form submit
 */

/* ── Processing overlay (pure DOM fallback for Swal) ──────── */
if (typeof window.showProcessingOverlayFallback === 'undefined') {
    window.showProcessingOverlayFallback = function (title, text) {
        const existing = document.getElementById('global-processing-overlay');
        if (existing) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.id = 'global-processing-overlay';
        overlay.style.position = 'fixed';
        overlay.style.inset = '0';
        overlay.style.background = 'rgba(15, 23, 42, 0.5)';
        overlay.style.display = 'grid';
        overlay.style.placeItems = 'center';
        overlay.style.zIndex = '9999';

        const card = document.createElement('div');
        card.style.background = '#fff';
        card.style.borderRadius = '12px';
        card.style.padding = '16px 18px';
        card.style.width = 'min(360px, calc(100vw - 24px))';
        card.style.boxShadow = '0 18px 50px rgba(15, 23, 42, 0.25)';
        card.style.textAlign = 'center';

        const spinner = document.createElement('div');
        spinner.style.width = '28px';
        spinner.style.height = '28px';
        spinner.style.margin = '0 auto 10px';
        spinner.style.border = '3px solid #e2e8f0';
        spinner.style.borderTopColor = '#ea580c';
        spinner.style.borderRadius = '50%';
        spinner.style.animation = 'hrisSpin 0.9s linear infinite';

        const heading = document.createElement('strong');
        heading.textContent = title;
        heading.style.display = 'block';
        heading.style.marginBottom = '6px';
        heading.style.color = '#0f172a';

        const message = document.createElement('p');
        message.textContent = text;
        message.style.margin = '0';
        message.style.color = '#475569';
        message.style.fontSize = '0.92rem';

        card.appendChild(spinner);
        card.appendChild(heading);
        card.appendChild(message);
        overlay.appendChild(card);
        document.body.appendChild(overlay);

        if (!document.getElementById('global-processing-overlay-style')) {
            const style = document.createElement('style');
            style.id = 'global-processing-overlay-style';
            style.textContent = '@keyframes hrisSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
            document.head.appendChild(style);
        }
    };
}

/* ── Uppercase input handler ──────────────────────────────── */
document.addEventListener('input', function (event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (!target.matches('[data-uppercase-input]')) return;
    target.value = target.value.toUpperCase();
});

document.querySelectorAll('input[data-uppercase-input]').forEach(function (input) {
    input.value = input.value.toUpperCase();
});

/* ── Form processing-submit handler ───────────────────────── */
document.querySelectorAll('form[data-processing-submit]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (form.dataset.processingBypass === '1') return;
        event.preventDefault();

        const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
        const processingTitle  = form.dataset.processingTitle      || 'Processing request';
        const processingText   = form.dataset.processingText       || 'Please wait while your request is being processed.';
        const processingBtnTxt = form.dataset.processingButtonText || 'Processing...';

        if (submitButton instanceof HTMLButtonElement || submitButton instanceof HTMLInputElement) {
            submitButton.disabled = true;

            if (submitButton instanceof HTMLButtonElement && submitButton.dataset.originalText === undefined) {
                submitButton.dataset.originalText = submitButton.textContent || '';
                submitButton.textContent = processingBtnTxt;
            }

            if (submitButton instanceof HTMLInputElement && submitButton.dataset.originalText === undefined) {
                submitButton.dataset.originalText = submitButton.value;
                submitButton.value = processingBtnTxt;
            }
        }

        if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
            Swal.fire({
                title: processingTitle,
                text: processingText,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: function () {
                    Swal.showLoading();
                },
            });
        } else {
            window.showProcessingOverlayFallback(processingTitle, processingText);
        }

        form.dataset.processingBypass = '1';
        window.setTimeout(function () {
            form.submit();
        }, 80);
    });
});
