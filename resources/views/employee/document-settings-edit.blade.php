@extends('dashboards.layout', [
    'title' => 'Edit Document Type',
    'subtitle' => 'Update document template settings.',
])

@section('content')
@php
$p       = $documentType->parts ?? [];
$bodyD   = is_array($p['body'] ?? null)
               ? $p['body']
               : ['text' => ($p['body'] ?? ''), 'font' => 'Arial', 'size' => 12, 'color' => '#000000',
                  'bold' => false, 'italic' => false, 'underline' => false];
$closingD = is_array($p['closing_remark'] ?? null)
               ? $p['closing_remark']
               : ['text' => ($p['closing_remark'] ?? ''), 'font' => 'Arial', 'size' => 12, 'color' => '#000000',
                  'bold' => false, 'italic' => false, 'underline' => false];
$footerD = is_array($p['footer'] ?? null)
               ? $p['footer']
               : ['text' => ($p['footer'] ?? ''), 'font' => 'Calibri', 'size' => 10, 'color' => '#000000',
                  'italic' => true, 'underline' => false, 'image' => null];
$sigsD    = is_array($p['signatories'] ?? null) ? $p['signatories'] : [];
$hdrImg   = $p['header_image'] ?? null;
$ftrImg   = $footerD['image'] ?? null;
$phStyles = is_array($p['placeholder_styles'] ?? null) ? $p['placeholder_styles'] : [];

$fonts = ['Arial', 'Times New Roman', 'Calibri', 'Georgia', 'Verdana'];
@endphp

<style>
.fc { max-width: 960px; margin: 0 auto; background: #fff; padding: 32px; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
.fs { margin-bottom: 28px; padding-bottom: 28px; border-bottom: 1px solid #e5e7eb; }
.fs:last-of-type { border-bottom: none; }
.fs h3 { margin: 0 0 18px; font-size: 1.05em; color: #1f2937; font-weight: 600; }
.fg { margin-bottom: 18px; }
label { display: block; margin-bottom: 5px; font-weight: 500; font-size: .92em; color: #374151; }
.fctl { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: .95em; box-sizing: border-box; }
.fctl:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
.is-invalid { border-color: #ef4444; }
.inv { color: #ef4444; font-size: .82em; display: block; margin-top: 4px; }
.hint { color: #6b7280; font-size: .8em; display: block; margin-top: 5px; }
.hint code { background: #f3f4f6; padding: 1px 5px; border-radius: 3px; font-family: monospace; }
.font-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 12px; margin-bottom: 8px; }
.font-bar select, .font-bar input[type=number] { padding: 4px 6px; border: 1px solid #d1d5db; border-radius: 3px; font-size: .88em; }
.font-bar input[type=number] { width: 64px; }
.font-bar input[type=color] { width: 36px; height: 28px; padding: 2px; border: 1px solid #d1d5db; border-radius: 3px; cursor: pointer; }
.font-bar label { display: inline-flex; align-items: center; gap: 3px; font-weight: 600; margin: 0; font-size: .88em; cursor: pointer; }
.font-bar .sep { color: #d1d5db; user-select: none; }
.img-preview { max-width: 260px; max-height: 80px; border: 1px solid #e5e7eb; border-radius: 4px; margin-bottom: 8px; display: block; }
.img-drop { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.fa { display: flex; gap: 10px; margin-top: 28px; }
.btn { padding: 10px 22px; border: none; border-radius: 4px; cursor: pointer; font-size: .95em; font-weight: 500; }
.btn-primary { background: #2563eb; color: #fff; }
.btn-primary:hover { background: #1d4ed8; }
.btn-secondary { background: #6b7280; color: #fff; text-decoration: none; }
.btn-sm { padding: 6px 12px; font-size: .85em; }
.btn-success { background: #16a34a; color: #fff; }
.btn-danger-sm { background: #ef4444; color: #fff; border: none; border-radius: 50%; width: 26px; height: 26px; cursor: pointer; font-size: 15px; line-height: 1; display: flex; align-items: center; justify-content: center; position: absolute; top: 10px; right: 10px; }
.sig-row { border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin-bottom: 14px; position: relative; background: #fafafa; }
.sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px; }
.sub-label { font-size: .8em; color: #6b7280; display: block; margin-bottom: 4px; font-weight: 500; }
.alert-danger { background: #fee2e2; border: 1px solid #fca5a5; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; }
.esign-callout { background: #fffbeb; border: 2px solid #d97706; border-radius: 8px; padding: 16px 18px; margin-top: 18px; }
.esign-callout-label { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 1em; color: #92400e; cursor: pointer; margin: 0; }
.esign-callout-label input[type=checkbox] { width: 20px; height: 20px; accent-color: #d97706; cursor: pointer; flex-shrink: 0; }
.esign-callout-hint { margin: 8px 0 0 30px; font-size: .85em; color: #b45309; }
</style>

<div class="fc">
    @if ($errors->any())
        <div class="alert-danger">
            <ul style="margin:0;padding-left:18px;">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          id="documentTypeForm"
          action="{{ route('employee.document-settings.update', $documentType->id) }}"
          enctype="multipart/form-data">
        @csrf
        @method('PUT')

        {{-- ── Basic Info ──────────────────────────────── --}}
        <div class="fs">
            <h3>Basic Information</h3>
            <div class="fg">
                <label for="name">Document Type Name <span style="color:#ef4444">*</span></label>
                <input type="text" id="name" name="name"
                       class="fctl @error('name') is-invalid @enderror"
                       value="{{ old('name', $documentType->name) }}" required>
                @error('name') <span class="inv">{{ $message }}</span> @enderror
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="fg">
                    <label for="title">Document Title / Heading</label>
                    <input type="text" id="title" name="title"
                           class="fctl @error('title') is-invalid @enderror"
                           placeholder="e.g., CERTIFICATE OF EMPLOYMENT"
                           value="{{ old('title', $p['title'] ?? '') }}">
                    @error('title') <span class="inv">{{ $message }}</span> @enderror
                </div>
                <div class="fg">
                    <label for="salutation">Salutation</label>
                    <input type="text" id="salutation" name="salutation"
                           class="fctl @error('salutation') is-invalid @enderror"
                           placeholder="e.g., To Whom It May Concern:"
                           value="{{ old('salutation', $p['salutation'] ?? '') }}">
                    @error('salutation') <span class="inv">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- ── Header Image ─────────────────────────────── --}}
        <div class="fs">
            <h3>Header Image (Banner)</h3>
            <div class="img-drop">
                @if ($hdrImg)
                    <img src="{{ asset('storage/' . ltrim($hdrImg, '/')) }}" class="img-preview" id="hdr-preview">
                @else
                    <img src="#" class="img-preview" id="hdr-preview" style="display:none;">
                @endif
                <div>
                    <input type="file" name="header_image" id="header_image"
                           accept="image/*" class="fctl"
                           style="width:auto;"
                           onchange="previewImg(this,'hdr-preview')">
                    <span class="hint">PNG / JPG / WebP max 4 MB. Leave blank to keep current.</span>
                </div>
            </div>
            @error('header_image') <span class="inv">{{ $message }}</span> @enderror
        </div>

        {{-- ── Document Body ──────────────────────────── --}}
        <div class="fs">
            <h3>Document Body</h3>
            <div class="font-bar">
                <select name="body_font" title="Font family">
                    @foreach($fonts as $f)
                        <option value="{{ $f }}" {{ old('body_font', $bodyD['font'] ?? 'Arial') === $f ? 'selected' : '' }}>{{ $f }}</option>
                    @endforeach
                </select>
                <input type="number" name="body_size" min="6" max="72"
                       value="{{ old('body_size', $bodyD['size'] ?? 12) }}" title="Font size (pt)">
                <input type="color" name="body_color"
                       value="{{ old('body_color', $bodyD['color'] ?? '#000000') }}" title="Font color">
                <span class="sep">|</span>
                <label title="Bold"><input type="checkbox" name="body_bold" value="1"
                    {{ !empty(old('body_bold', $bodyD['bold'] ?? false)) ? 'checked' : '' }}> <strong>B</strong></label>
                <label title="Italic"><input type="checkbox" name="body_italic" value="1"
                    {{ !empty(old('body_italic', $bodyD['italic'] ?? false)) ? 'checked' : '' }}> <em>I</em></label>
                <label title="Underline"><input type="checkbox" name="body_underline" value="1"
                    {{ !empty(old('body_underline', $bodyD['underline'] ?? false)) ? 'checked' : '' }}> <u>U</u></label>
            </div>
            <textarea name="body_text" id="body_text"
                      class="fctl @error('body_text') is-invalid @enderror"
                      rows="10"
                      placeholder="Main content of the document.">{{ old('body_text', $bodyD['text'] ?? '') }}</textarea>
            @error('body_text') <span class="inv">{{ $message }}</span> @enderror
            <span class="hint">
                Placeholders: <code>{employee_name}</code>, <code>{date}</code>,
                <code>{designation}</code> (from users.designation),
                <code>{employee_type}</code> (Permanent / Job Order / Contractual / Elected Official),
                <code>{department}</code>,
                <code>{salary}</code> (monthly salary from latest payroll run),
                <code>{honorific}</code> (Mr./Ms., from the employee's PDS Sex answer - prints "Mr./Ms." if not yet set),
                <code>{last_name}</code> (employee's last name only),
                <code>{pronoun}</code> (He/She, from the same PDS Sex answer - prints "He/She" if not yet set)
            </span>

            <div style="margin-top:16px;border-top:1px dashed #e5e7eb;padding-top:14px;">
                <p class="hint" style="margin-bottom:10px;font-size:.82em;">Placeholder font styles - set how each placeholder value is rendered in the document:</p>
                @php
                $phLabels = [
                    'employee_name' => ['{employee_name}', 'Employee Name'],
                    'date'          => ['{date}',          'Date'],
                    'designation'   => ['{designation}',   'Designation'],
                    'employee_type' => ['{employee_type}', 'Employee Type'],
                    'department'    => ['{department}',    'Department'],
                    'salary'        => ['{salary}',        'Monthly Salary'],
                    'honorific'     => ['{honorific}',      'Honorific/Title'],
                    'last_name'     => ['{last_name}',      'Last Name'],
                    'pronoun'       => ['{pronoun}',        'Pronoun (He/She)'],
                ];
                @endphp
                @foreach($phLabels as $phKey => [$phToken, $phLabel])
                @php $phD = $phStyles[$phKey] ?? []; @endphp
                <div style="margin-bottom:8px;">
                    <span class="sub-label"><code>{{ $phToken }}</code> - {{ $phLabel }}</span>
                    <div class="font-bar">
                        <select name="ph_{{ $phKey }}_font" title="Font family">
                            @foreach($fonts as $f)
                                <option value="{{ $f }}" {{ old('ph_'.$phKey.'_font', $phD['font'] ?? 'Arial') === $f ? 'selected' : '' }}>{{ $f }}</option>
                            @endforeach
                        </select>
                        <input type="number" name="ph_{{ $phKey }}_size" min="6" max="72"
                               value="{{ old('ph_'.$phKey.'_size', $phD['size'] ?? 12) }}" title="Font size (pt)">
                        <input type="color" name="ph_{{ $phKey }}_color"
                               value="{{ old('ph_'.$phKey.'_color', $phD['color'] ?? '#000000') }}" title="Font color">
                        <span class="sep">|</span>
                        <label title="Bold"><input type="checkbox" name="ph_{{ $phKey }}_bold" value="1"
                            {{ !empty(old('ph_'.$phKey.'_bold', $phD['bold'] ?? false)) ? 'checked' : '' }}> <strong>B</strong></label>
                        <label title="Italic"><input type="checkbox" name="ph_{{ $phKey }}_italic" value="1"
                            {{ !empty(old('ph_'.$phKey.'_italic', $phD['italic'] ?? false)) ? 'checked' : '' }}> <em>I</em></label>
                        <label title="Underline"><input type="checkbox" name="ph_{{ $phKey }}_underline" value="1"
                            {{ !empty(old('ph_'.$phKey.'_underline', $phD['underline'] ?? false)) ? 'checked' : '' }}> <u>U</u></label>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- ── Closing Remark ──────────────────────────── --}}
        <div class="fs">
            <h3>Closing Remark</h3>
            <div class="font-bar">
                <select name="closing_remark_font" title="Font family">
                    @foreach($fonts as $f)
                        <option value="{{ $f }}" {{ old('closing_remark_font', $closingD['font'] ?? 'Arial') === $f ? 'selected' : '' }}>{{ $f }}</option>
                    @endforeach
                </select>
                <input type="number" name="closing_remark_size" min="6" max="72"
                       value="{{ old('closing_remark_size', $closingD['size'] ?? 12) }}" title="Font size (pt)">
                <input type="color" name="closing_remark_color"
                       value="{{ old('closing_remark_color', $closingD['color'] ?? '#000000') }}" title="Font color">
                <span class="sep">|</span>
                <label title="Bold"><input type="checkbox" name="closing_remark_bold" value="1"
                    {{ !empty(old('closing_remark_bold', $closingD['bold'] ?? false)) ? 'checked' : '' }}> <strong>B</strong></label>
                <label title="Italic"><input type="checkbox" name="closing_remark_italic" value="1"
                    {{ !empty(old('closing_remark_italic', $closingD['italic'] ?? false)) ? 'checked' : '' }}> <em>I</em></label>
                <label title="Underline"><input type="checkbox" name="closing_remark_underline" value="1"
                    {{ !empty(old('closing_remark_underline', $closingD['underline'] ?? false)) ? 'checked' : '' }}> <u>U</u></label>
            </div>
            <textarea name="closing_remark_text"
                      class="fctl @error('closing_remark_text') is-invalid @enderror"
                      rows="3"
                      placeholder="e.g., Given this {date} in Calapan City upon the request of {employee_name}.">{{ old('closing_remark_text', $closingD['text'] ?? '') }}</textarea>
            @error('closing_remark_text') <span class="inv">{{ $message }}</span> @enderror
        </div>

        {{-- ── Signatories ─────────────────────────────── --}}
        <div class="fs">
            <h3>Signatories</h3>
            <p style="font-size:.88em;color:#6b7280;margin-bottom:14px;">
                Name is rendered bold &amp; larger; Designation is italic &amp; smaller. Block is centered in the printed document.
            </p>
            <div id="sig-container"></div>
            <button type="button" id="add-sig" class="btn btn-success btn-sm" style="margin-top:4px;">
                + Add Signatory
            </button>

            <div class="esign-callout">
                <label for="requires_esignature" class="esign-callout-label">
                    <input type="checkbox" id="requires_esignature" name="requires_esignature" value="1"
                           {{ old('requires_esignature', $documentType->requires_esignature) ? 'checked' : '' }}>
                    <span><i class="fas fa-signature"></i> Require HR Manager e-signature</span>
                </label>
                <p class="esign-callout-hint">
                    When enabled, an Accepted request of this type must be forwarded to and digitally
                    signed (PNPKI) by the HR Manager before Front Desk can print and complete it.
                </p>
            </div>
        </div>

        {{-- ── Footer ──────────────────────────────────── --}}
        <div class="fs">
            <h3>Footer</h3>
            <div class="font-bar">
                <select name="footer_font" title="Font family">
                    @foreach($fonts as $f)
                        <option value="{{ $f }}" {{ old('footer_font', $footerD['font'] ?? 'Calibri') === $f ? 'selected' : '' }}>{{ $f }}</option>
                    @endforeach
                </select>
                <input type="number" name="footer_size" min="6" max="72"
                       value="{{ old('footer_size', $footerD['size'] ?? 10) }}" title="Font size (pt)">
                <input type="color" name="footer_color"
                       value="{{ old('footer_color', $footerD['color'] ?? '#000000') }}" title="Font color">
                <span class="sep">|</span>
                <label title="Italic"><input type="checkbox" name="footer_italic" value="1"
                    {{ !empty(old('footer_italic', $footerD['italic'] ?? true)) ? 'checked' : '' }}> <em>I</em></label>
                <label title="Underline"><input type="checkbox" name="footer_underline" value="1"
                    {{ !empty(old('footer_underline', $footerD['underline'] ?? false)) ? 'checked' : '' }}> <u>U</u></label>
            </div>
            <textarea name="footer_text"
                      class="fctl @error('footer_text') is-invalid @enderror"
                      rows="3"
                      placeholder="e.g., Not Valid Without Official Seal.&#10;O.R. No:&#10;Date:">{{ old('footer_text', $footerD['text'] ?? '') }}</textarea>
            @error('footer_text') <span class="inv">{{ $message }}</span> @enderror

            <div class="img-drop" style="margin-top:12px;">
                @if ($ftrImg)
                    <img src="{{ asset('storage/' . ltrim($ftrImg, '/')) }}" class="img-preview" id="ftr-preview">
                @else
                    <img src="#" class="img-preview" id="ftr-preview" style="display:none;">
                @endif
                <div>
                    <label for="footer_image" style="display:inline-block;margin-bottom:4px;">Footer Image (Logo / Seal)</label>
                    <input type="file" name="footer_image" id="footer_image"
                           accept="image/*" class="fctl"
                           style="width:auto;"
                           onchange="previewImg(this,'ftr-preview')">
                    <span class="hint">PNG / JPG / WebP max 4 MB. Leave blank to keep current.</span>
                </div>
            </div>
            @error('footer_image') <span class="inv">{{ $message }}</span> @enderror
        </div>

        <div class="fa">
            <button type="submit" class="btn btn-primary">Update Document Type</button>
            <a href="{{ route('employee.document-settings') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const FONTS = @json($fonts);
    const existing = @json($sigsD);
    let idx = 0;

    function fontOpts(sel) {
        return FONTS.map(f =>
            `<option value="${f}"${f === sel ? ' selected' : ''}>${f}</option>`
        ).join('');
    }

    function chk(val) { return val ? ' checked' : ''; }

    function buildRow(d) {
        const i = idx++;
        const el = document.createElement('div');
        el.className = 'sig-row';
        el.innerHTML = `
            <button type="button" class="btn-danger-sm" onclick="this.closest('.sig-row').remove()" title="Remove">×</button>
            <div class="sig-grid">
                <div>
                    <label>Signatory Name</label>
                    <input type="text" name="signatories[${i}][name]" class="fctl"
                           value="${escHtml(d.name||'')}" placeholder="Full Name">
                </div>
                <div>
                    <label>Designation / Title</label>
                    <input type="text" name="signatories[${i}][designation]" class="fctl"
                           value="${escHtml(d.designation||'')}" placeholder="e.g., HR Manager">
                </div>
            </div>

            <span class="sub-label">Name font style</span>
            <div class="font-bar" style="margin-bottom:10px;">
                <select name="signatories[${i}][name_font]">${fontOpts(d.name_font||'Times New Roman')}</select>
                <input type="number" name="signatories[${i}][name_size]" min="6" max="72"
                       value="${d.name_size||14}" title="pt">
                <input type="color" name="signatories[${i}][name_color]" value="${d.name_color||'#000000'}">
                <span class="sep">|</span>
                <label><input type="checkbox" name="signatories[${i}][name_bold]" value="1"${chk(d.name_bold!==false)}> <strong>B</strong></label>
                <label><input type="checkbox" name="signatories[${i}][name_italic]" value="1"${chk(d.name_italic)}> <em>I</em></label>
            </div>

            <span class="sub-label">Designation font style</span>
            <div class="font-bar">
                <select name="signatories[${i}][desig_font]">${fontOpts(d.desig_font||'Times New Roman')}</select>
                <input type="number" name="signatories[${i}][desig_size]" min="6" max="72"
                       value="${d.desig_size||11}" title="pt">
                <input type="color" name="signatories[${i}][desig_color]" value="${d.desig_color||'#000000'}">
                <span class="sep">|</span>
                <label><input type="checkbox" name="signatories[${i}][desig_italic]" value="1"${chk(d.desig_italic!==false)}> <em>I</em></label>
            </div>
        `;
        document.getElementById('sig-container').appendChild(el);
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    if (existing.length) {
        existing.forEach(buildRow);
    } else {
        buildRow({});
    }

    document.getElementById('add-sig').addEventListener('click', () => buildRow({}));
})();

function previewImg(input, previewId) {
    const img = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        img.src = URL.createObjectURL(input.files[0]);
        img.style.display = 'block';
    }
}

(function () {
    const form = document.getElementById('documentTypeForm');

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed === '1') return;

        e.preventDefault();

        const Swal = window.Swal;
        if (!Swal) {
            if (window.confirm('Update this document type?')) {
                form.dataset.confirmed = '1';
                form.submit();
            }
            return;
        }

        Swal.fire({
            icon: 'question',
            title: 'Update Document Type?',
            text: 'This will overwrite the current template with your changes.',
            showCancelButton: true,
            confirmButtonText: 'Yes, Update',
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (result.isConfirmed) {
                form.dataset.confirmed = '1';
                form.submit();
            }
        });
    });
})();
</script>
@endsection
