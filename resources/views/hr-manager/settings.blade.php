@extends('dashboards.layout', [
    'title' => 'System Settings',
    'subtitle' => 'Configure modules, templates, and operational parameters for HRIS.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
    <style>
        .settings-tabs { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; padding-bottom:.5rem; }
        .settings-tab-btn { padding:.5rem 1rem; border:none; background:none; cursor:pointer; font-size:.875rem; font-weight:500; color:#6b7280; border-radius:.375rem .375rem 0 0; transition:background .15s,color .15s; }
        .settings-tab-btn:hover { background:#f3f4f6; color:#111827; }
        .settings-tab-btn.active { background:#fff; color:#1d4ed8; border-bottom:2px solid #1d4ed8; margin-bottom:-2px; }
        .settings-panel { display:none; }
        .settings-panel.active { display:block; }
        .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .settings-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; }
        .settings-section-title { font-size:.875rem; font-weight:600; color:#374151; margin:1.25rem 0 .5rem; text-transform:uppercase; letter-spacing:.05em; }
        .settings-hint { font-size:.75rem; color:#6b7280; margin-top:.25rem; }
        .toggle-row { display:flex; align-items:center; gap:.75rem; padding:.5rem 0; }
        .toggle-row label { font-size:.875rem; color:#374151; cursor:pointer; }
        @media (max-width:640px) { .settings-grid, .settings-grid-3 { grid-template-columns:1fr; } }
    </style>
@endsection

@section('content')

    <section class="hrm-module" data-module="settings">
        <form class="hrm-form-card" method="POST" action="{{ route('hr-manager.settings.update') }}">
            @csrf

            {{-- Tab navigation --}}
            <div class="settings-tabs" id="settingsTabs">
                <button type="button" class="settings-tab-btn active" data-tab="general">General</button>
                <button type="button" class="settings-tab-btn" data-tab="modules">Modules</button>
                <button type="button" class="settings-tab-btn" data-tab="signatories">Signatories</button>
                <button type="button" class="settings-tab-btn" data-tab="attendance">Attendance</button>
                <button type="button" class="settings-tab-btn" data-tab="notifications">Notifications</button>
                <button type="button" class="settings-tab-btn" data-tab="export">Export</button>
                <button type="button" class="settings-tab-btn" data-tab="dashboard">Dashboard</button>
                <button type="button" class="settings-tab-btn" data-tab="database">Database</button>
            </div>

            {{-- ── GENERAL ── --}}
            <div class="settings-panel active" id="tab-general">
                <p class="settings-hint" style="margin-bottom:1rem;">Organization identity and regional defaults displayed across HRIS.</p>

                <div class="settings-grid">
                    <div class="form-group">
                        <label for="system_name">System Name</label>
                        <input type="text" class="hrm-input" id="system_name" name="system_name"
                               value="{{ old('system_name', $settings->system_name ?? 'HRIS') }}"
                               maxlength="100" placeholder="e.g. HRIS">
                        @error('system_name')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="org_name">Organization Name</label>
                        <input type="text" class="hrm-input" id="org_name" name="org_name"
                               value="{{ old('org_name', $settings->org_name ?? 'City Government of Calapan') }}"
                               maxlength="255" placeholder="e.g. City Government of Calapan">
                        @error('org_name')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="support_email">Support / Contact Email</label>
                        <input type="email" class="hrm-input" id="support_email" name="support_email"
                               value="{{ old('support_email', $settings->support_email ?? '') }}"
                               placeholder="e.g. hris@lgucalapan.ph">
                        <span class="settings-hint">Shown on the login page contact message.</span>
                        @error('support_email')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="timezone">Timezone</label>
                        <input type="text" class="hrm-input" id="timezone" name="timezone"
                               value="{{ old('timezone', $settings->timezone ?? 'Asia/Manila') }}"
                               maxlength="100" placeholder="e.g. Asia/Manila">
                        <span class="settings-hint">PHP timezone identifier. Requires cache:clear after save.</span>
                        @error('timezone')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="date_format">Date Display Format</label>
                        <input type="text" class="hrm-input" id="date_format" name="date_format"
                               value="{{ old('date_format', $settings->date_format ?? 'Y-m-d') }}"
                               maxlength="50" placeholder="e.g. Y-m-d or m/d/Y">
                        <span class="settings-hint">PHP date() format string used across reports.</span>
                        @error('date_format')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            {{-- ── MODULES ── --}}
            <div class="settings-panel" id="tab-modules">
                <p class="settings-hint" style="margin-bottom:1rem;">Enable or disable HRIS modules. Disabled modules hide their navigation items.</p>

                <div class="settings-section-title">Core Modules</div>
                <div class="toggle-row">
                    <input type="hidden" name="records_enabled" value="0">
                    <input type="checkbox" id="records_enabled" name="records_enabled" value="1"
                           @checked($settings && $settings->records_enabled)>
                    <label for="records_enabled">Records Module</label>
                </div>
                <div class="toggle-row">
                    <input type="hidden" name="leave_enabled" value="0">
                    <input type="checkbox" id="leave_enabled" name="leave_enabled" value="1"
                           @checked($settings && $settings->leave_enabled)>
                    <label for="leave_enabled">Leave Module</label>
                </div>
                <div class="toggle-row">
                    <input type="hidden" name="frontdesk_enabled" value="0">
                    <input type="checkbox" id="frontdesk_enabled" name="frontdesk_enabled" value="1"
                           @checked($settings && $settings->frontdesk_enabled)>
                    <label for="frontdesk_enabled">Front Desk / Document Requests Module</label>
                </div>
                <div class="toggle-row">
                    <input type="hidden" name="payroll_enabled" value="0">
                    <input type="checkbox" id="payroll_enabled" name="payroll_enabled" value="1"
                           @checked($settings && ($settings->payroll_enabled ?? true))>
                    <label for="payroll_enabled">Payroll Module</label>
                </div>
                <div class="toggle-row">
                    <input type="hidden" name="attendance_enabled" value="0">
                    <input type="checkbox" id="attendance_enabled" name="attendance_enabled" value="1"
                           @checked($settings && ($settings->attendance_enabled ?? true))>
                    <label for="attendance_enabled">Attendance / DTR Module</label>
                </div>
                <div class="toggle-row">
                    <input type="hidden" name="eta_enabled" value="0">
                    <input type="checkbox" id="eta_enabled" name="eta_enabled" value="1"
                           @checked($settings && ($settings->eta_enabled ?? true))>
                    <label for="eta_enabled">ETA / Travel Order Module</label>
                </div>

                <div class="settings-section-title" style="margin-top:1.5rem;">Alert Threshold</div>
                <div style="max-width:200px;">
                    <label for="pending_alert_threshold">Pending Request Alert Threshold</label>
                    <input type="number" class="hrm-input" id="pending_alert_threshold"
                           name="pending_alert_threshold"
                           value="{{ old('pending_alert_threshold', $settings->pending_alert_threshold ?? 50) }}"
                           min="1">
                    <span class="settings-hint">Badge turns red when pending items exceed this count.</span>
                    @error('pending_alert_threshold')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
            </div>

            {{-- ── SIGNATORIES ── --}}
            <div class="settings-panel" id="tab-signatories">
                <p class="settings-hint" style="margin-bottom:1rem;">Names and designations that appear on official HRIS-generated documents (Leave Forms, ETAs, payslips, Job Order Appointment documents).</p>

                <div class="hrm-signatory-grid settings-grid">
                    <div class="form-group">
                        <label for="mayor_name">City Mayor - Name</label>
                        <input type="text" class="hrm-input" id="mayor_name" name="mayor_name"
                               value="{{ old('mayor_name', $settings->mayor_name ?? '') }}"
                               placeholder="e.g. Juan dela Cruz">
                        @error('mayor_name')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="mayor_designation">City Mayor - Designation</label>
                        <input type="text" class="hrm-input" id="mayor_designation" name="mayor_designation"
                               value="{{ old('mayor_designation', $settings->mayor_designation ?? '') }}"
                               placeholder="e.g. City Mayor">
                        @error('mayor_designation')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="vice_mayor_name">City Vice Mayor - Name</label>
                        <input type="text" class="hrm-input" id="vice_mayor_name" name="vice_mayor_name"
                               value="{{ old('vice_mayor_name', $settings->vice_mayor_name ?? '') }}"
                               placeholder="e.g. Maria Santos">
                        @error('vice_mayor_name')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="vice_mayor_designation">City Vice Mayor - Designation</label>
                        <input type="text" class="hrm-input" id="vice_mayor_designation" name="vice_mayor_designation"
                               value="{{ old('vice_mayor_designation', $settings->vice_mayor_designation ?? '') }}"
                               placeholder="e.g. City Vice Mayor">
                        @error('vice_mayor_designation')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="hr_manager_name">HR Manager - Name</label>
                        <input type="text" class="hrm-input" id="hr_manager_name" name="hr_manager_name"
                               value="{{ old('hr_manager_name', $settings->hr_manager_name ?? '') }}"
                               placeholder="e.g. Ana Reyes">
                        @error('hr_manager_name')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="hr_manager_designation">HR Manager - Designation</label>
                        <input type="text" class="hrm-input" id="hr_manager_designation" name="hr_manager_designation"
                               value="{{ old('hr_manager_designation', $settings->hr_manager_designation ?? '') }}"
                               placeholder="e.g. Human Resource Management Officer">
                        @error('hr_manager_designation')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="budget_officer_name">Budget Officer - Name</label>
                        <input type="text" class="hrm-input" id="budget_officer_name" name="budget_officer_name"
                               value="{{ old('budget_officer_name', $settings->budget_officer_name ?? '') }}"
                               placeholder="e.g. Jannette M. Villas">
                        @error('budget_officer_name')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="budget_officer_designation">Budget Officer - Designation</label>
                        <input type="text" class="hrm-input" id="budget_officer_designation" name="budget_officer_designation"
                               value="{{ old('budget_officer_designation', $settings->budget_officer_designation ?? '') }}"
                               placeholder="e.g. OIC City Budget Dept.">
                        @error('budget_officer_designation')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            {{-- ── ATTENDANCE ── --}}
            <div class="settings-panel" id="tab-attendance">
                <p class="settings-hint" style="margin-bottom:1rem;">Shift schedule used by the DTR punch resolver and Form 48 export to compute tardiness, undertime, and slot assignments.</p>

                <div class="settings-section-title">Shift Schedule (HH:MM, 24-hour)</div>
                <div class="settings-grid-3">
                    <div class="form-group">
                        <label for="work_start">Work Start</label>
                        <input type="time" class="hrm-input" id="work_start" name="work_start"
                               value="{{ old('work_start', $settings->work_start ?? '08:00') }}">
                        <span class="settings-hint">Morning tardiness baseline.</span>
                        @error('work_start')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="morning_end">Morning Window End</label>
                        <input type="time" class="hrm-input" id="morning_end" name="morning_end"
                               value="{{ old('morning_end', $settings->morning_end ?? '11:00') }}">
                        <span class="settings-hint">Upper bound for AM punch slot.</span>
                        @error('morning_end')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="lunch_return">Lunch Return</label>
                        <input type="time" class="hrm-input" id="lunch_return" name="lunch_return"
                               value="{{ old('lunch_return', $settings->lunch_return ?? '13:00') }}">
                        <span class="settings-hint">PM tardiness baseline.</span>
                        @error('lunch_return')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="noon_end">Noon Window End</label>
                        <input type="time" class="hrm-input" id="noon_end" name="noon_end"
                               value="{{ old('noon_end', $settings->noon_end ?? '14:00') }}">
                        <span class="settings-hint">Upper bound for PM arrival slot.</span>
                        @error('noon_end')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="work_end">Work End</label>
                        <input type="time" class="hrm-input" id="work_end" name="work_end"
                               value="{{ old('work_end', $settings->work_end ?? '17:00') }}">
                        <span class="settings-hint">Undertime baseline.</span>
                        @error('work_end')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="settings-section-title" style="margin-top:1.5rem;">Payroll Rule</div>
                <div style="max-width:220px;">
                    <label for="payroll_working_days_per_month">Working Days per Month</label>
                    <input type="number" class="hrm-input" id="payroll_working_days_per_month"
                           name="payroll_working_days_per_month"
                           value="{{ old('payroll_working_days_per_month', $settings->payroll_working_days_per_month ?? 22) }}"
                           min="1" max="31">
                    <span class="settings-hint">Used as the divisor for LWOP deduction (standard CSC: 22).</span>
                    @error('payroll_working_days_per_month')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>

                <div class="settings-section-title" style="margin-top:1.5rem;">Leave Balance Display</div>
                <div style="max-width:220px;">
                    <label for="leave_balance_decimals">Leave Balance Decimal Places</label>
                    <input type="number" class="hrm-input" id="leave_balance_decimals"
                           name="leave_balance_decimals"
                           value="{{ old('leave_balance_decimals', $settings->leave_balance_decimals ?? 3) }}"
                           min="0" max="5">
                    <span class="settings-hint">Precision for VL/SL balance values on leave forms and exports.</span>
                    @error('leave_balance_decimals')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>

                <div class="settings-section-title" style="margin-top:1.5rem;">Automatic Import</div>
                <p class="settings-hint" style="margin-bottom:1rem;">
                    When enabled, a background scheduler pulls biometric punch logs automatically
                    for yesterday and today on the configured interval. Results are written to the audit log.
                </p>

                <div class="toggle-row">
                    <input type="checkbox" id="auto_import_enabled" name="auto_import_enabled" value="1"
                           {{ old('auto_import_enabled', $settings->auto_import_enabled ?? false) ? 'checked' : '' }}>
                    <label for="auto_import_enabled">Enable automatic biometric import</label>
                </div>

                <div class="settings-grid" style="margin-top:1rem;">
                    <div class="form-group">
                        <label for="auto_import_interval_minutes">Interval (minutes)</label>
                        <input type="number" class="hrm-input" id="auto_import_interval_minutes"
                               name="auto_import_interval_minutes"
                               value="{{ old('auto_import_interval_minutes', $settings->auto_import_interval_minutes ?? 30) }}"
                               min="1" max="1440">
                        <span class="settings-hint">
                            Minutes to wait between sweeps. Minimum is 1 minute.
                        </span>
                        @error('auto_import_interval_minutes')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="auto_import_dept_id">Department <span style="font-weight:400;color:#94a3b8;">- optional, blank = all</span></label>
                        <select class="hrm-input" id="auto_import_dept_id" name="auto_import_dept_id">
                            <option value="">All Departments</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->Dept_id }}"
                                    {{ old('auto_import_dept_id', $settings->auto_import_dept_id ?? '') == $dept->Dept_id ? 'selected' : '' }}>
                                    {{ $dept->Dept_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('auto_import_dept_id')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="auto_import_page_size">Rows per sweep</label>
                        <input type="number" class="hrm-input" id="auto_import_page_size"
                               name="auto_import_page_size"
                               value="{{ old('auto_import_page_size', $settings->auto_import_page_size ?? 100) }}"
                               min="10" max="5000">
                        <span class="settings-hint">
                            How many rows to fetch per API call. Lower values reduce memory usage per sweep.
                        </span>
                        @error('auto_import_page_size')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            {{-- ── NOTIFICATIONS ── --}}
            <div class="settings-panel" id="tab-notifications">
                <p class="settings-hint" style="margin-bottom:1rem;">Email notification settings - from address, display name, and default templates.</p>

                <div class="settings-section-title">Email From</div>
                <div class="settings-grid">
                    <div class="form-group">
                        <label for="mail_from_address">From Address</label>
                        <input type="email" class="hrm-input" id="mail_from_address" name="mail_from_address"
                               value="{{ old('mail_from_address', $settings->mail_from_address ?? config('mail.from.address', '')) }}"
                               placeholder="e.g. no-reply@lgucalapan.ph">
                        <span class="settings-hint">Overrides the MAIL_FROM_ADDRESS environment variable at runtime.</span>
                        @error('mail_from_address')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="mail_from_name">From Display Name</label>
                        <input type="text" class="hrm-input" id="mail_from_name" name="mail_from_name"
                               value="{{ old('mail_from_name', $settings->mail_from_name ?? config('mail.from.name', '')) }}"
                               placeholder="e.g. City Human Resource Department">
                        @error('mail_from_name')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="settings-section-title" style="margin-top:1.5rem;">Default Email Template</div>
                <div class="form-group">
                    <label for="email_template_subject">Subject</label>
                    <input type="text" class="hrm-input" id="email_template_subject"
                           name="email_template_subject"
                           value="{{ old('email_template_subject', $settings->email_template_subject ?? '') }}"
                           placeholder="e.g. HRIS Notification">
                    @error('email_template_subject')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="email_template_body">Body</label>
                    <textarea class="hrm-input" id="email_template_body" name="email_template_body"
                              rows="4" placeholder="Automated message body...">{{ old('email_template_body', $settings->email_template_body ?? '') }}</textarea>
                    @error('email_template_body')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
            </div>

            {{-- ── EXPORT ── --}}
            <div class="settings-panel" id="tab-export">
                <p class="settings-hint" style="margin-bottom:1rem;">PDF and Excel export defaults for leave forms, Form 48, and payslips.</p>

                <div class="settings-section-title">Excel / Form 48 & Monitoring Matrix</div>
                <div class="toggle-row" style="margin-bottom:.5rem;">
                    <input type="hidden" name="excel_protection_enabled" value="0">
                    <input type="checkbox" id="excel_protection_enabled" name="excel_protection_enabled" value="1"
                           @checked($settings && ($settings->excel_protection_enabled ?? true))>
                    <label for="excel_protection_enabled">Enable Sheet Protection on Form 48 & Monitoring Matrix Exports</label>
                </div>
                <div style="max-width:300px;">
                    <label for="excel_sheet_password">Form 48 & Monitoring Matrix Sheet Password</label>
                    <input type="password" class="hrm-input" id="excel_sheet_password"
                           name="excel_sheet_password"
                           autocomplete="new-password"
                           placeholder="{{ ($settings->excel_sheet_password ?? '') !== '' ? '(password set - leave blank to keep)' : 'Set a new password' }}">
                    <span class="settings-hint">Password required to edit the exported Form 48 and Monitoring Matrix spreadsheets.</span>
                    @error('excel_sheet_password')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>

                <div class="settings-section-title" style="margin-top:1.5rem;">PDF Leave Form</div>
                <div class="settings-grid" style="max-width:500px;">
                    <div class="form-group">
                        <label for="pdf_font_family">Font Family</label>
                        <input type="text" class="hrm-input" id="pdf_font_family" name="pdf_font_family"
                               value="{{ old('pdf_font_family', $settings->pdf_font_family ?? 'Arial') }}"
                               maxlength="100" placeholder="e.g. Arial">
                        <span class="settings-hint">Must be a font supported by FPDI/FPDF.</span>
                        @error('pdf_font_family')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="pdf_font_size">Font Size (pt)</label>
                        <input type="number" class="hrm-input" id="pdf_font_size" name="pdf_font_size"
                               value="{{ old('pdf_font_size', $settings->pdf_font_size ?? 9) }}"
                               min="6" max="72">
                        @error('pdf_font_size')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            {{-- ── DASHBOARD ── --}}
            <div class="settings-panel" id="tab-dashboard">
                <p class="settings-hint" style="margin-bottom:1rem;">Performance and display settings for the HR Manager and Department Head dashboards.</p>

                <div class="settings-section-title">Cache</div>
                <div style="max-width:220px;">
                    <label for="dashboard_cache_ttl">Dashboard Cache Duration (minutes)</label>
                    <input type="number" class="hrm-input" id="dashboard_cache_ttl"
                           name="dashboard_cache_ttl"
                           value="{{ old('dashboard_cache_ttl', $settings->dashboard_cache_ttl ?? 10) }}"
                           min="1" max="120">
                    <span class="settings-hint">How long chart and summary card data is cached. Lower = fresher data, higher = faster load.</span>
                    @error('dashboard_cache_ttl')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
            </div>

            {{-- Save button --}}
            <div id="settings-save-row" style="margin-top:1.75rem; padding-top:1rem; border-top:1px solid #e5e7eb;">
                <button type="submit" class="hrm-btn hrm-alert-success">Save Settings</button>
            </div>
        </form>

        {{-- ── DATABASE (separate form - needs multipart) ── --}}
        <div class="settings-panel" id="tab-database">
            <p class="settings-hint" style="margin-bottom:1rem;">Download a full SQL backup of the HRIS database or restore from a previously downloaded backup file.</p>

            {{-- Backup --}}
            <div class="settings-section-title">Backup</div>
            <p style="font-size:.875rem;color:#374151;margin-bottom:.75rem;">
                Downloads a <code>.sql</code> file containing all tables and data.
                The file can be used to fully restore the database if needed.
            </p>
            <a href="{{ route('hr-manager.settings.backup') }}"
               class="hrm-btn"
               style="display:inline-block;text-decoration:none;">
                &#8595; Download Database Backup
            </a>

            <div style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid #e5e7eb;">
                {{-- Restore --}}
                <div class="settings-section-title" style="color:#b91c1c;">Restore</div>
                <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:.375rem;padding:.75rem 1rem;margin-bottom:1rem;">
                    <strong style="color:#b91c1c;">Warning:</strong>
                    Restoring a backup will <strong>drop and recreate all tables</strong> and overwrite every row with the contents of the uploaded file.
                    This action cannot be undone. Download a fresh backup first if you have unsaved data.
                </div>
                <form method="POST"
                      action="{{ route('hr-manager.settings.restore') }}"
                      enctype="multipart/form-data"
                      id="restoreForm">
                    @csrf
                    <div class="form-group" style="max-width:420px;margin-bottom:1rem;">
                        <label for="backup_file">SQL Backup File</label>
                        <input type="file" class="hrm-input" id="backup_file" name="backup_file" accept=".sql,.txt">
                        <span class="settings-hint">Must be a <code>.sql</code> file generated by the Download Backup button above. Max size: 512 MB.</span>
                        @error('backup_file')<span class="hrm-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="toggle-row" style="margin-bottom:1rem;">
                        <input type="checkbox" id="restore_confirm" name="restore_confirm" value="1">
                        <label for="restore_confirm" style="color:#b91c1c;font-weight:500;">
                            I understand this will permanently overwrite all existing data.
                        </label>
                        @error('restore_confirm')<span class="hrm-error" style="display:block;margin-top:.25rem;">{{ $message }}</span>@enderror
                    </div>
                    <button type="submit"
                            class="hrm-btn"
                            style="background:#dc2626;color:#fff;border-color:#dc2626;">
                        Restore from Backup
                    </button>
                </form>
            </div>
        </div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
    <script>
        (function () {
            const tabs = document.querySelectorAll('.settings-tab-btn');
            const panels = document.querySelectorAll('.settings-panel');
            const saveRow = document.getElementById('settings-save-row');

            function activate(id) {
                tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === id));
                panels.forEach(p => p.classList.toggle('active', p.id === 'tab-' + id));
                if (saveRow) saveRow.style.display = id === 'database' ? 'none' : '';
                try { sessionStorage.setItem('hris_settings_tab', id); } catch (_) {}
            }

            tabs.forEach(t => t.addEventListener('click', () => activate(t.dataset.tab)));

            const hash = window.location.hash.replace('#tab-', '');
            const saved = sessionStorage.getItem('hris_settings_tab');
            if (hash && document.getElementById('tab-' + hash)) {
                activate(hash);
            } else if (saved && document.getElementById('tab-' + saved)) {
                activate(saved);
            }

            @if(session('success'))
                window.addEventListener('load', function () {
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Saved',
                            text: @json(session('success')),
                            timer: 3000,
                            timerProgressBar: true,
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                        });
                    }
                });
            @endif

            @if($errors->any())
                window.addEventListener('load', function () {
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Please fix the following errors',
                            html: '<ul style="text-align:left;margin:.5rem 0 0 1.25rem;">'
                                + @json(collect($errors->all())->map(fn($e) => '<li>'.$e.'</li>')->join(''))
                                + '</ul>',
                        });
                    }
                });
            @endif

            const restoreForm = document.getElementById('restoreForm');
            if (restoreForm) {
                restoreForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (!window.Swal) { this.submit(); return; }
                    Swal.fire({
                        icon: 'warning',
                        title: 'Restore database?',
                        html: 'This will <strong>drop and recreate all tables</strong> and overwrite every row with the contents of the uploaded file.<br><br>This action <strong>cannot be undone</strong>.',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, restore',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        focusCancel: true,
                    }).then(function (result) {
                        if (result.isConfirmed) restoreForm.submit();
                    });
                });
            }
        })();
    </script>
@endsection
