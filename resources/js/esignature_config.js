import SignaturePad from 'signature_pad';

const esignatureForm = document.getElementById('esignature-request-form');
const SIGNER_NAME = esignatureForm.dataset.signerName || 'your name';
const VERIFY_PASSWORD_URL = esignatureForm.dataset.verifyPasswordUrl;

const canvas = document.getElementById('signature-pad');
const signaturePad = new SignaturePad(canvas);

document.getElementById('clear-signature').addEventListener('click', () => {
    signaturePad.clear();
    updatePreview();
});

signaturePad.addEventListener('endStroke', () => updatePreview());

// Upload panel: any selected image is normalized onto a canvas the same
// size as the draw pad (scaled to fit, aspect ratio preserved, centered)
// and exported the same way (toDataURL('image/png')) so the server never
// needs to know or care which panel produced the signature - both paths
// feed the same hidden #signature-input with the same PNG data URI shape.
const uploadCanvas = document.getElementById('signature-upload-preview');
const uploadCtx = uploadCanvas.getContext('2d');
const fileInput = document.getElementById('signature-file-input');
let uploadedImageLoaded = false;
const MAX_UPLOAD_BYTES = 1 * 1024 * 1024;

function clearUploadPreview() {
    uploadCtx.clearRect(0, 0, uploadCanvas.width, uploadCanvas.height);
    uploadedImageLoaded = false;
    fileInput.value = '';
    updatePreview();
}

document.getElementById('browse-signature').addEventListener('click', () => {
    fileInput.click();
});

document.getElementById('clear-upload').addEventListener('click', clearUploadPreview);

fileInput.addEventListener('change', () => {
    const file = fileInput.files[0];
    const errorEl = document.getElementById('signature-error');

    if (!file) {
        return;
    }

    if (!file.type.startsWith('image/') || file.size > MAX_UPLOAD_BYTES) {
        clearUploadPreview();
        errorEl.textContent = 'Please choose an image file under 1MB.';
        errorEl.style.display = 'block';
        return;
    }

    const reader = new FileReader();
    reader.onload = () => {
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(uploadCanvas.width / img.width, uploadCanvas.height / img.height);
            const drawWidth = img.width * scale;
            const drawHeight = img.height * scale;
            const offsetX = (uploadCanvas.width - drawWidth) / 2;
            const offsetY = (uploadCanvas.height - drawHeight) / 2;

            uploadCtx.clearRect(0, 0, uploadCanvas.width, uploadCanvas.height);
            uploadCtx.drawImage(img, offsetX, offsetY, drawWidth, drawHeight);
            uploadedImageLoaded = true;
            errorEl.style.display = 'none';
            updatePreview();
        };
        img.src = reader.result;
    };
    reader.readAsDataURL(file);
});

// Tabs just toggle which panel is visible/active - the submit handler and
// the preview below decide which panel's content to use based on this
// same active state.
const tabDraw = document.getElementById('tab-draw');
const tabUpload = document.getElementById('tab-upload');
const panelDraw = document.getElementById('panel-draw');
const panelUpload = document.getElementById('panel-upload');
let activeMode = 'draw';

tabDraw.addEventListener('click', () => {
    activeMode = 'draw';
    tabDraw.classList.add('active');
    tabUpload.classList.remove('active');
    panelDraw.classList.add('active');
    panelUpload.classList.remove('active');
    updatePreview();
});

tabUpload.addEventListener('click', () => {
    activeMode = 'upload';
    tabUpload.classList.add('active');
    tabDraw.classList.remove('active');
    panelUpload.classList.add('active');
    panelDraw.classList.remove('active');
    updatePreview();
});

// Shared by the submit handler and the live preview, so "what counts as
// a provided signature right now" is defined in exactly one place.
function getCurrentSignatureDataUrl() {
    if (activeMode === 'draw' && !signaturePad.isEmpty()) {
        return signaturePad.toDataURL('image/png');
    }

    if (activeMode === 'upload' && uploadedImageLoaded) {
        return uploadCanvas.toDataURL('image/png');
    }

    return null;
}

// Approximates SignESignatureRequestPdfJob::buildStampText()'s four wording
// variants client-side, for preview purposes only - the real wording
// (and the real signer name, from the certificate) is decided
// server-side at actual signing time. Never fully blank, matching the
// server-side fallback, so the preview never looks like a rendering bug.
function updatePreview() {
    const previewImg = document.getElementById('preview-signature-img');
    const dataUrl = getCurrentSignatureDataUrl();

    if (dataUrl) {
        previewImg.src = dataUrl;
        previewImg.classList.add('visible');
    } else {
        previewImg.classList.remove('visible');
    }

    const includeName = document.getElementById('include_name').checked;
    const includeDate = document.getElementById('include_date').checked;
    const nameLine = document.getElementById('preview-name-line');
    const dateLine = document.getElementById('preview-date-line');
    if (!includeName && !includeDate) {
        nameLine.textContent = 'Digitally Signed.';
        dateLine.textContent = '';
        return;
    }

    nameLine.textContent = includeName ? `Digitally signed by ${SIGNER_NAME}.` : 'Digitally Signed.';

    if (includeDate) {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        const formatted = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} `
            + `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
        dateLine.textContent = `Date: ${formatted}`;
    } else {
        dateLine.textContent = '';
    }
}

document.getElementById('include_name').addEventListener('change', updatePreview);
document.getElementById('include_date').addEventListener('change', updatePreview);

esignatureForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const errorEl = document.getElementById('signature-error');
    const dataUrl = getCurrentSignatureDataUrl();

    if (!dataUrl) {
        errorEl.textContent = 'Please provide a signature before submitting.';
        errorEl.style.display = 'block';
        return;
    }

    errorEl.style.display = 'none';
    document.getElementById('signature-input').value = dataUrl;

    const form = event.target;
    const message = 'This saves your certificate, trust chain, and signature as your e-signature setting, replacing any existing one on file.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Save e-signature setting?',
            html: `${message}<br><br>Enter your certificate password to confirm:`,
            icon: 'question',
            input: 'password',
            inputLabel: 'Certificate password',
            inputPlaceholder: 'Certificate password',
            inputAttributes: { autocomplete: 'off' },
            showCancelButton: true,
            confirmButtonText: 'Yes, Save',
            showLoaderOnConfirm: true,
            allowOutsideClick: () => !Swal.isLoading(),
            // Checked here, against the certificate already sitting in the file input,
            // rather than waiting for the real submit - a wrong password there would mean
            // a full page reload, and browsers never let any file input (this one included)
            // be repopulated after that. Catching it here keeps every selected file intact
            // no matter how many times the password is retried.
            preConfirm: async (password) => {
                if (!password) {
                    Swal.showValidationMessage('Please enter your certificate password.');
                    return false;
                }

                const certificateFile = document.getElementById('pnpki_certificate').files[0];
                if (!certificateFile) {
                    Swal.showValidationMessage('Please choose your certificate file first.');
                    return false;
                }

                const verifyData = new FormData();
                verifyData.append('pnpki_certificate', certificateFile);
                verifyData.append('pnpki_password', password);

                try {
                    const response = await fetch(VERIFY_PASSWORD_URL, {
                        method: 'POST',
                        body: verifyData,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                    const result = await response.json();

                    if (!response.ok || !result.valid) {
                        Swal.showValidationMessage(result.message || 'That password did not unlock the certificate you uploaded.');
                        return false;
                    }

                    return password;
                } catch (error) {
                    Swal.showValidationMessage('Could not verify your password right now. Please try again.');
                    return false;
                }
            },
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('pnpki_password_hidden').value = result.value;
                form.submit();
            }
        });
    } else {
        const password = prompt(`${message}\n\nEnter your certificate password to confirm:`);
        if (password) {
            document.getElementById('pnpki_password_hidden').value = password;
            form.submit();
        }
    }
});

// Only present when a setting already exists (see index.blade.php's @if block).
const removeBtn = document.getElementById('remove-esignature-btn');
if (removeBtn) {
    removeBtn.addEventListener('click', () => {
        const message = 'This permanently deletes your saved certificate, trust chain, and signature. You can save a new one anytime after.';

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Remove e-signature setting?',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Remove',
                confirmButtonColor: '#dc2626',
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('esignature-remove-form').submit();
                }
            });
        } else if (confirm(message)) {
            document.getElementById('esignature-remove-form').submit();
        }
    });
}

// Server-side "wrong password" comes back as a plain validation error after a full
// page reload (back()->withErrors(...)), not from live JS - window.esignaturePasswordError
// is set by an inline script (in index.blade.php, ahead of this module) only when that
// specific error is present, so this fires once, right when the page loads.
if (window.esignaturePasswordError) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'error',
            title: 'Incorrect certificate password',
            text: window.esignaturePasswordError,
            confirmButtonText: 'Try again',
        });
    } else {
        alert(window.esignaturePasswordError);
    }
}

updatePreview();
