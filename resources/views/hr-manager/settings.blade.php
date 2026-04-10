@extends('dashboards.layout', [
    'title' => 'System Settings',
    'subtitle' => 'Configure modules, templates, and alert thresholds for HRIS.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    @if(session('success'))
        <div class="hrm-alert hrm-alert-success" style="margin-bottom:1rem;">
            {{ session('success') }}
        </div>
    @endif

    <section class="hrm-module" data-module="settings">
        <form class="hrm-form-card" method="POST" action="{{ route('hr-manager.settings.update') }}" enctype="multipart/form-data">
            @csrf

            {{-- Module Configuration --}}
            <h4>Module Configuration</h4>
            <label><input type="checkbox" name="records_enabled" value="1" @checked($settings && $settings->records_enabled)> Records Module Enabled</label>
            <label><input type="checkbox" name="leave_enabled" value="1" @checked($settings && $settings->leave_enabled)> Leave Module Enabled</label>
            <label><input type="checkbox" name="frontdesk_enabled" value="1" @checked($settings && $settings->frontdesk_enabled)> Front Desk Module Enabled</label>

            <label>Pending Request Alert Threshold
                <input type="number" name="pending_alert_threshold" value="{{ $settings->pending_alert_threshold ?? 50 }}" min="1">
            </label>

            {{-- Email Templates --}}
            <h4>Email Templates</h4>
            <label>Subject
                <input type="text" name="email_template_subject" value="{{ $settings->email_template_subject ?? '' }}">
            </label>
            <label>Body
                <textarea name="email_template_body" rows="4">{{ $settings->email_template_body ?? '' }}</textarea>
            </label>

            {{-- Official Document Assets --}}
            <h4>Official Document Assets</h4>
            <label>Header Image
                <input type="file" accept="image/*">
            </label>
            <label>Footer Image
                <input type="file" accept="image/*">
            </label>

            {{-- Signatories --}}
            <h4>Document Signatories</h4>
            <p style="color:#666;font-size:.875rem;margin-bottom:1rem;">
                These names and designations will appear on official HRIS-generated documents such as Leave Forms.
            </p>

            <div class="hrm-signatory-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label for="mayor_name">City Mayor — Name</label>
                    <input type="text" class="hrm-input" id="mayor_name" name="mayor_name"
                           value="{{ old('mayor_name', $settings->mayor_name ?? '') }}"
                           placeholder="e.g. Juan dela Cruz">
                    @error('mayor_name')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="mayor_designation">City Mayor — Designation</label>
                    <input type="text" class="hrm-input" id="mayor_designation" name="mayor_designation"
                           value="{{ old('mayor_designation', $settings->mayor_designation ?? '') }}"
                           placeholder="e.g. City Mayor">
                    @error('mayor_designation')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="vice_mayor_name">City Vice Mayor — Name</label>
                    <input type="text" class="hrm-input" id="vice_mayor_name" name="vice_mayor_name"
                           value="{{ old('vice_mayor_name', $settings->vice_mayor_name ?? '') }}"
                           placeholder="e.g. Maria Santos">
                    @error('vice_mayor_name')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="vice_mayor_designation">City Vice Mayor — Designation</label>
                    <input type="text" class="hrm-input" id="vice_mayor_designation" name="vice_mayor_designation"
                           value="{{ old('vice_mayor_designation', $settings->vice_mayor_designation ?? '') }}"
                           placeholder="e.g. City Vice Mayor">
                    @error('vice_mayor_designation')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="hr_manager_name">HR Manager — Name</label>
                    <input type="text" class="hrm-input" id="hr_manager_name" name="hr_manager_name"
                           value="{{ old('hr_manager_name', $settings->hr_manager_name ?? '') }}"
                           placeholder="e.g. Ana Reyes">
                    @error('hr_manager_name')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="hr_manager_designation">HR Manager — Designation</label>
                    <input type="text" class="hrm-input" id="hr_manager_designation" name="hr_manager_designation"
                           value="{{ old('hr_manager_designation', $settings->hr_manager_designation ?? '') }}"
                           placeholder="e.g. Human Resource Management Officer">
                    @error('hr_manager_designation')<span class="hrm-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div style="margin-top:1.5rem;">
                <button type="submit" class="hrm-btn hrm-alert-success">Save Settings</button>
            </div>
        </form>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
