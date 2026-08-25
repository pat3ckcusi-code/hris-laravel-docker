@extends('dashboards.layout', [
    'title' => 'E-Signature Config',
    'subtitle' => 'Save your PNPKI e-signature for signing documents.',
])

@section('page_head')
    @vite('resources/css/esignature_config.css')
@endsection

@section('content')
    <div class="esig-page">
        @if (session('status'))
            <div class="esig-status ready">
                <i class="fas fa-circle-check"></i>
                <div><strong>{{ session('status') }}</strong></div>
            </div>
        @endif

        <div class="esig-intro">
            <i class="fas fa-shield-halved"></i>
            <p>
                Your certificate and trust chain are saved (certificate encrypted at rest) so you don't need to
                re-upload them every time. Your certificate <strong>password is never saved</strong> — it's only used
                here to confirm it unlocks the certificate you upload, then discarded.
            </p>
        </div>

        <div class="chip"><i class="fas fa-user"></i> Signing as: {{ auth()->user()->name }}</div>

        @if ($esignatureSetting)
            <div class="esig-section">
                <div class="esig-current-header">
                    <h2 class="esig-section-title"><i class="fas fa-id-badge"></i> Current e-signature on file</h2>
                    <button type="button" id="remove-esignature-btn" class="esig-btn-ghost esig-btn-danger"><i class="fas fa-trash"></i> Remove</button>
                </div>
                <p class="esig-section-hint">Saved {{ $esignatureSetting->updated_at->format('M j, Y g:i A') }}. Uploading below replaces it, or remove it entirely.</p>
                <div class="appearance-preview">
                    <div class="preview-text">
                        <p><i class="fas fa-file"></i> Certificate on file ({{ count($esignatureSetting->intermediate_paths) }} intermediate {{ Str::plural('certificate', count($esignatureSetting->intermediate_paths)) }} in chain)</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('esignature-config.destroy') }}" id="esignature-remove-form" style="display:none;">
                @csrf
                @method('DELETE')
            </form>
        @endif

        <form method="POST" action="{{ route('esignature-config.store') }}" id="esignature-request-form" enctype="multipart/form-data" data-signer-name="{{ auth()->user()->name }}" data-verify-password-url="{{ route('esignature-config.verify-password') }}">
            @csrf

            <div class="esig-columns">
            <div class="esig-section">
                <h2 class="esig-section-title"><i class="fas fa-signature"></i> Signature</h2>
                <p class="esig-section-hint">Draw your signature below, or upload an image of it.</p>

                <div class="signature-tabs">
                    <button type="button" id="tab-draw" class="active">Draw</button>
                    <button type="button" id="tab-upload">Upload</button>
                </div>

                <div class="signature-panel active" id="panel-draw">
                    <canvas id="signature-pad" width="500" height="160"></canvas>
                    <div class="pad-actions">
                        <button type="button" id="clear-signature" class="esig-btn-ghost"><i class="fas fa-eraser"></i> Clear</button>
                    </div>
                </div>

                <div class="signature-panel" id="panel-upload">
                    <canvas id="signature-upload-preview" width="500" height="160"></canvas>
                    <input type="file" id="signature-file-input" accept="image/png,image/jpeg" style="display: none;">
                    <div class="pad-actions">
                        <button type="button" id="browse-signature" class="esig-btn-ghost"><i class="fas fa-folder-open"></i> Browse&hellip;</button>
                        <button type="button" id="clear-upload" class="esig-btn-ghost"><i class="fas fa-eraser"></i> Clear</button>
                    </div>
                </div>

                <div class="appearance-preview">
                    <img id="preview-signature-img" alt="Signature preview">
                    <div class="preview-text">
                        <p id="preview-name-line"></p>
                        <p id="preview-date-line"></p>
                    </div>
                </div>
                <p class="preview-caption">Preview only — the exact name shown comes from your certificate once signed.</p>

                <div class="field-error" id="signature-error" style="display: none;">Please provide a signature before submitting.</div>
                @error('signature')
                    <div class="field-error">{{ $message }}</div>
                @enderror

                <input type="hidden" name="signature" id="signature-input">

                <div class="esig-checkboxes">
                    <label class="checkbox-label">
                        <input type="checkbox" id="include_name" name="include_name" value="1" {{ old('include_name', $esignatureSetting->include_name ?? true) ? 'checked' : '' }}>
                        Include my name
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="include_date" name="include_date" value="1" {{ old('include_date', $esignatureSetting->include_date ?? true) ? 'checked' : '' }}>
                        Include date signed
                    </label>
                </div>
            </div>

            <div class="esig-section">
                <h2 class="esig-section-title"><i class="fas fa-id-badge"></i> PNPKI Certificate &amp; Trust Chain</h2>
                <p class="esig-section-hint">Your government-issued certificate and the CA chain used to validate it.</p>

                <div class="esig-field-row">
                    <div class="esig-field">
                        <label for="pnpki_certificate">Certificate (.p12/.pfx)</label>
                        <input type="file" id="pnpki_certificate" name="pnpki_certificate" accept=".p12,.pfx" required>
                        @error('pnpki_certificate')
                            <div class="field-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <!-- Certificate password is deliberately not a field on this page - it's
                     entered in the "Save e-signature setting?" confirmation dialog instead
                     (see esignature_config.js), and populated into this hidden input right
                     before the form actually submits. Never displayed on the page itself. -->
                <input type="hidden" id="pnpki_password_hidden" name="pnpki_password">

                <div class="esig-field-row">
                    <div class="esig-field">
                        <label for="chain_root_ca">Trust chain: root CA certificate</label>
                        <input type="file" id="chain_root_ca" name="chain_root_ca" accept=".pem,.cer,.crt" required>
                        @error('chain_root_ca')
                            <div class="field-error">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="esig-field">
                        <label for="chain_intermediates">Trust chain: intermediate CA certificate(s)</label>
                        <input type="file" id="chain_intermediates" name="chain_intermediates[]" accept=".pem,.cer,.crt" multiple required>
                        @error('chain_intermediates')
                            <div class="field-error">{{ $message }}</div>
                        @enderror
                        @error('chain_intermediates.*')
                            <div class="field-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
            </div>

            <div class="esig-submit-row">
                <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
@endsection

@section('page_scripts')
    @error('pnpki_password')
        <script>
            window.esignaturePasswordError = @json($message);
        </script>
    @enderror
    @vite('resources/js/esignature_config.js')
@endsection
