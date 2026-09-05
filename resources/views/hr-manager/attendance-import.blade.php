@extends('dashboards.layout', [
    'title' => 'Import Attendance Logs',
    'subtitle' => 'Pull biometric punch logs from the integration API for a date range.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
    <style>
        /* Tabs */
        .import-tabs {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: .5rem;
        }
        .import-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .5rem 1rem;
            border: none;
            background: none;
            cursor: pointer;
            font-size: .875rem;
            font-weight: 500;
            color: #6b7280;
            border-radius: .375rem .375rem 0 0;
            transition: background .15s, color .15s;
        }
        .import-tab-btn:hover { background: #f3f4f6; color: #111827; }
        .import-tab-btn.active {
            background: #fff;
            color: #117a8b;
            border-bottom: 2px solid #17a2b8;
            margin-bottom: -2px;
        }
        .import-panel { display: none; }
        .import-panel.active { display: block; }
        .import-tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.3rem;
            height: 1.3rem;
            padding: 0 .35rem;
            border-radius: 999px;
            background: #f59e0b;
            color: #fff;
            font-size: .7rem;
            font-weight: 700;
        }
        .import-layout {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 1.5rem;
            align-items: start;
        }
        .import-form-card {
            background: #fff;
            border: 1px solid #d9e2ef;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 6px 20px rgba(31, 45, 61, 0.06);
        }
        .import-form-card h3 {
            margin: 0 0 0.25rem;
            font-size: 1.05rem;
            color: #1f2d3d;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .import-form-card h3 i { color: #17a2b8; }
        .import-subtitle {
            font-size: 0.85rem;
            color: #6c757d;
            margin: 0 0 1.5rem;
            line-height: 1.5;
        }
        .import-form-card input,
        .import-form-card select {
            width: 100%;
            border: 1px solid #cdd9e5;
            border-radius: 6px;
            padding: 0.48rem 0.62rem;
            background: #fff;
            font-size: 0.9rem;
            color: #1f2d3d;
        }
        .import-form-card input:focus,
        .import-form-card select:focus {
            outline: none;
            border-color: #17a2b8;
            box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.12);
        }
        .import-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #3d5166;
            display: block;
            margin-bottom: 0.35rem;
        }
        .import-label-hint {
            font-size: 0.78rem;
            font-weight: 400;
            color: #94a3b8;
        }
        .import-date-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .import-field-error {
            font-size: 0.78rem;
            color: #b91c1c;
            margin-top: 0.25rem;
            display: block;
        }
        .import-presets {
            display: flex;
            gap: 0.45rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .import-preset-btn {
            border: 1px solid #cdd9e5;
            background: #f8fafc;
            border-radius: 6px;
            padding: 0.3rem 0.65rem;
            font-size: 0.78rem;
            color: #3d5166;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .import-preset-btn:hover {
            background: #e0f5f8;
            border-color: #17a2b8;
            color: #117a8b;
        }
        .import-divider {
            border: none;
            border-top: 1px solid #e8eff6;
            margin: 1.25rem 0;
        }
        .import-submit-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .import-submit-note {
            font-size: 0.78rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        /* Info panel */
        .import-info-panel {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .import-info-card {
            background: #f8fafc;
            border: 1px solid #e2ecf5;
            border-radius: 10px;
            padding: 1rem 1.1rem;
        }
        .import-info-card h4 {
            margin: 0 0 0.6rem;
            font-size: 0.875rem;
            color: #1f2d3d;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .import-info-card h4 i { color: #17a2b8; font-size: 0.82rem; }
        .import-info-steps {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .import-info-steps li {
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
            font-size: 0.82rem;
            color: #4d5f73;
            line-height: 1.5;
        }
        .import-step-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 50%;
            background: #17a2b8;
            color: #fff;
            font-size: 0.68rem;
            font-weight: 700;
            flex-shrink: 0;
            margin-top: 0.1rem;
        }
        .import-audit-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.82rem;
            color: #17a2b8;
            text-decoration: none;
            font-weight: 600;
        }
        .import-audit-link:hover { text-decoration: underline; }
        .import-warning-card {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            display: flex;
            gap: 0.6rem;
            align-items: flex-start;
        }
        .import-warning-card i { color: #f59e0b; margin-top: 0.15rem; flex-shrink: 0; }
        .import-warning-card p {
            margin: 0;
            font-size: 0.8rem;
            color: #78350f;
            line-height: 1.5;
        }
        /* Recent import results */
        .import-result-entry {
            font-size: 0.78rem;
            padding: 0.45rem 0.65rem;
            border-radius: 6px;
            border: 1px solid transparent;
        }
        .import-result-entry--ok { background: #f0fdf4; border-color: #bbf7d0; }
        .import-result-entry--neutral { background: #f8fafc; border-color: #e2e8f0; }
        .import-result-entry--error { background: #fef2f2; border-color: #fca5a5; }
        .import-result-entry-head { font-weight: 600; }
        .import-result-entry--ok .import-result-entry-head { color: #15803d; }
        .import-result-entry--neutral .import-result-entry-head { color: #64748b; }
        .import-result-entry--error .import-result-entry-head { color: #b91c1c; }
        .import-result-entry-error { color: #7f1d1d; margin-top: 0.2rem; word-break: break-word; }
        .import-result-entry-msg { color: #475569; margin-top: 0.15rem; }
        .import-result-entry-note { color: #94a3b8; margin-top: 0.15rem; font-style: italic; }
        .import-result-entry-meta { color: #94a3b8; margin-top: 0.2rem; }
        /* Alerts */
        .import-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        .import-alert i { flex-shrink: 0; margin-top: 0.15rem; }
        .import-alert-success {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: #1b5e20;
        }
        .import-alert-success i { color: #2e7d32; }
        .import-alert-error {
            background: #fde7e9;
            border: 1px solid #f5a0a8;
            color: #7f1d1d;
        }
        .import-alert-error i { color: #b91c1c; }
        @media (max-width: 900px) {
            .import-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .import-date-grid { grid-template-columns: 1fr; }
        }
        /* Employee search dropdown (raw-feed diagnostic tool) */
        .check-emp-suggestion {
            display: block;
            width: 100%;
            text-align: left;
            border: none;
            background: #fff;
            padding: 0.5rem 0.7rem;
            font-size: 0.85rem;
            color: #1f2d3d;
            cursor: pointer;
        }
        .check-emp-suggestion:hover { background: #eef9fb; }
        .check-emp-suggestion small { display: block; color: #6c757d; font-size: 0.75rem; }
        .check-result-box {
            border-radius: 8px;
            padding: 0.75rem 0.9rem;
            line-height: 1.6;
        }
        .check-result-found { background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d; }
        .check-result-warning { background: #fffbeb; border: 1px solid #fde68a; color: #78350f; }
        .check-result-notfound { background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; }
        .check-result-error { background: #fde7e9; border: 1px solid #f5a0a8; color: #7f1d1d; }
        .check-punch-list { margin: 0.35rem 0 0; padding-left: 1.1rem; font-size: 0.82rem; }
        /* Unmatched-punch time chip */
        .import-punch-chip {
            display: inline-block;
            background: #fef9c3;
            color: #854d0e;
            border-radius: 4px;
            padding: 0.1rem 0.4rem;
            font-size: 0.78rem;
            margin: 0 0.2rem 0.2rem 0;
        }
        .diagnostics-stack > * + * { margin-top: 1.5rem; }
    </style>
@endsection

@section('content')
    @if(session('success'))
        <div class="import-alert import-alert-success" role="alert">
            <i class="fa-solid fa-circle-check"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if($errors->any())
        <div class="import-alert import-alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    @if(session('error'))
        <div class="import-alert import-alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    {{-- ── TAB NAVIGATION ── --}}
    <div class="import-tabs" id="importTabs">
        <button type="button" class="import-tab-btn active" data-tab="import">
            <i class="fa-solid fa-cloud-arrow-down"></i> Pull Punch Logs
        </button>
        <button type="button" class="import-tab-btn" data-tab="diagnostics">
            <i class="fa-solid fa-stethoscope"></i> Diagnostics &amp; Maintenance
            @if($unmatchedBadgeCount > 0)
                <span class="import-tab-badge" id="unmatched-badge" title="Approximate - refines once opened">{{ $unmatchedBadgeCount >= 300 ? '300+' : $unmatchedBadgeCount }}</span>
            @endif
        </button>
    </div>

    {{-- ── TAB: PULL PUNCH LOGS ── --}}
    <div class="import-panel active" id="tab-import">
        <div class="import-layout">

            {{-- ── FORM ── --}}
            <div class="import-form-card">
                <h3><i class="fa-solid fa-cloud-arrow-down"></i> Pull Biometric Punch Logs</h3>
                <p class="import-subtitle">
                    Fetches all punch records within the selected range and queues a background
                    job to process them.
                </p>

                <form method="POST" action="{{ route('hr-manager.attendance.import.store') }}">
                    @csrf

                    {{-- Date presets --}}
                    <div style="margin-bottom:1rem;">
                        <span class="import-label" style="margin-bottom:0.5rem;">Quick Select</span>
                        <div class="import-presets">
                            <button type="button" class="import-preset-btn" data-preset="this-week">This Week</button>
                            <button type="button" class="import-preset-btn" data-preset="this-month">This Month</button>
                            <button type="button" class="import-preset-btn" data-preset="last-month">Last Month</button>
                            <button type="button" class="import-preset-btn" data-preset="last-7">Last 7 Days</button>
                            <button type="button" class="import-preset-btn" data-preset="last-30">Last 30 Days</button>
                        </div>
                    </div>

                    {{-- Date range --}}
                    <div class="import-date-grid">
                        <div class="form-group">
                            <label class="import-label" for="from_date">From</label>
                            <input type="date" id="from_date" name="from_date"
                                   value="{{ old('from_date') }}" required>
                            @error('from_date')
                                <span class="import-field-error">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label class="import-label" for="to_date">To</label>
                            <input type="date" id="to_date" name="to_date"
                                   value="{{ old('to_date') }}" required>
                            @error('to_date')
                                <span class="import-field-error">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>

                    {{-- Department --}}
                    <div class="form-group">
                        <label class="import-label" for="dept_id">
                            Department
                            <span class="import-label-hint"> optional, blank = all</span>
                        </label>
                        <select id="dept_id" name="dept_id">
                            <option value="">All Departments</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->Dept_id }}"
                                    {{ old('dept_id') == $dept->Dept_id ? 'selected' : '' }}>
                                    {{ $dept->Dept_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <hr class="import-divider">

                    <div class="import-submit-row">
                        <button type="submit" class="hrm-btn">
                            <i class="fa-solid fa-upload" style="margin-right:0.35rem;"></i>
                            Queue Import
                        </button>
                        <span class="import-submit-note">
                            <i class="fa-solid fa-circle-info"></i>
                            Runs in the background - page won't wait.
                        </span>
                    </div>
                </form>
            </div>

            {{-- ── INFO PANEL ── --}}
            <aside class="import-info-panel">

                {{-- Auto-import status chip - HR Manager only --}}
                @if(\App\Support\RoleNormalizer::normalize((string) auth()->user()->access_level) === 'hr manager')
                    @if($setting?->auto_import_enabled)
                        @php
                            $autoInterval = $setting->auto_import_interval_minutes ?? 30;
                            $autoDeptLabel = $setting->auto_import_dept_id
                                ? ($departments->firstWhere('Dept_id', $setting->auto_import_dept_id)?->Dept_name ?? 'Dept #'.$setting->auto_import_dept_id)
                                : 'All Depts';
                        @endphp
                        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.65rem 0.9rem;background:#e0f5f8;border:1px solid #9dd5e0;border-left:4px solid #17a2b8;border-radius:8px;font-size:0.82rem;color:#0f5f6d;">
                            <i class="fa-solid fa-circle-play" style="color:#17a2b8;flex-shrink:0;"></i>
                            <span>
                                <strong>Auto-import ON</strong>
                                every {{ $autoInterval }} min &middot; {{ $autoDeptLabel }}
                            </span>
                        </div>
                    @else
                        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.65rem 0.9rem;background:#f1f5f9;border:1px solid #cbd5e1;border-left:4px solid #94a3b8;border-radius:8px;font-size:0.82rem;color:#475569;">
                            <i class="fa-solid fa-circle-pause" style="color:#94a3b8;flex-shrink:0;"></i>
                            <span>
                                <strong>Auto-import OFF</strong>
                                <a href="{{ route('hr-manager.settings') }}#tab-attendance" style="color:#17a2b8;font-weight:600;">configure in Settings</a>
                            </span>
                        </div>
                    @endif
                @endif

                {{-- EmpNo pre-flight check --}}
                @if($empNoCount === 0)
                    <div class="import-warning-card" style="border-left-color:#b91c1c;background:#fef2f2;border-color:#fca5a5;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#b91c1c;"></i>
                        <p><strong>No employees have an EmpNo set.</strong> Every biometric punch will be skipped. Go to Employees and enter each person's Employee Number before importing.</p>
                    </div>
                @else
                    <div style="font-size:0.78rem;color:#166534;padding:0.4rem 0.65rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;">
                        <i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:0.3rem;"></i>
                        {{ $empNoCount }} employee(s) configured with EmpNo.
                    </div>
                @endif

                {{-- Recent import results --}}
                @if($recentImports->isNotEmpty())
                <div class="import-info-card">
                    <h4><i class="fa-solid fa-clock-rotate-left"></i> Recent Import Results</h4>
                    <div style="display:flex;flex-direction:column;gap:0.5rem;">
                        @foreach($recentImports as $entry)
                            @php
                                $d        = $entry->details ?? [];
                                $ok       = ($d['status'] ?? '') === 'success';
                                $imported = $d['imported'] ?? 0;
                                $skipped  = $d['skipped'] ?? 0;
                                $err      = $d['error'] ?? null;
                                $msgs     = array_slice($d['messages'] ?? [], 0, 3);
                                $hasUnmatched = collect($d['messages'] ?? [])->contains(fn ($m) => str_contains($m, 'no matching HRIS EmpNo'));
                                $entryState = ($ok && $imported > 0) ? 'ok' : ($ok ? 'neutral' : 'error');
                            @endphp
                            <div class="import-result-entry import-result-entry--{{ $entryState }}">
                                <div class="import-result-entry-head">
                                    {{ $ok ? ($imported > 0 ? '✓' : '○') : '✗' }}
                                    {{ $d['from'] ?? '?' }}–{{ $d['to'] ?? '?' }}
                                    &nbsp;{{ $imported }} in / {{ $skipped }} skipped
                                </div>
                                @if($err)
                                    <div class="import-result-entry-error">{{ $err }}</div>
                                @endif
                                @foreach($msgs as $msg)
                                    <div class="import-result-entry-msg">{{ $msg }}</div>
                                @endforeach
                                @if($hasUnmatched)
                                    <div class="import-result-entry-note">
                                        Unmatched EmpNo checks run company-wide and are not limited to the scope below.
                                    </div>
                                @endif
                                <div class="import-result-entry-meta">
                                    {{ $entry->created_at->diffForHumans() }}
                                    &middot; Scope: {{ $d['dept_name'] ?? 'ALL' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="import-info-card">
                    <h4><i class="fa-solid fa-list-check"></i> How It Works</h4>
                    <ol class="import-info-steps">
                        <li>
                            <span class="import-step-num">1</span>
                            <span>HRIS authenticates with the biometric integration API.</span>
                        </li>
                        <li>
                            <span class="import-step-num">2</span>
                            <span>Punch logs are fetched in pages of 1,000 records.</span>
                        </li>
                        <li>
                            <span class="import-step-num">3</span>
                            <span>New records are written to <code>attendance_logs</code>; duplicates are skipped.</span>
                        </li>
                        <li>
                            <span class="import-step-num">4</span>
                            <span>DTR rows are recomputed for every affected employee.</span>
                        </li>
                        <li>
                            <span class="import-step-num">5</span>
                            <span>A summary entry is written to the audit log on completion.</span>
                        </li>
                    </ol>
                </div>

                @if(\App\Support\RoleNormalizer::normalize((string) auth()->user()->access_level) === 'hr manager')
                <div class="import-info-card" style="background:#eef9fb;border-color:#b2dfe9;">
                    <h4><i class="fa-solid fa-clipboard-list"></i> Check Results</h4>
                    <p style="font-size:0.82rem;color:#4d5f73;margin:0 0 0.65rem;line-height:1.5;">
                        Row counts and any errors are recorded in the audit log after the
                        background job completes.
                    </p>
                    <a href="{{ route('hr-manager.audit') }}" class="import-audit-link">
                        <i class="fa-solid fa-arrow-right-long"></i> View Audit Log
                    </a>
                </div>
                @endif

                <div class="import-warning-card">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <p>Re-importing an already-imported range is safe duplicates are detected by employee, date, and time and skipped automatically.</p>
                </div>

            </aside>
        </div>
    </div>

    {{-- ── TAB: DIAGNOSTICS & MAINTENANCE ── --}}
    <div class="import-panel" id="tab-diagnostics">
        <div class="diagnostics-stack">

        {{-- ── DIAGNOSTIC: check raw biometric feed for one employee/date ── --}}
        <div class="import-form-card" style="max-width:640px;position:relative;">
            <h3><i class="fa-solid fa-magnifying-glass"></i> Check Raw Biometric Feed</h3>
            <p class="import-subtitle">
                For one employee on one date: check the biometric API directly (skipping our own
                import) to tell "our app missed this" apart from "the biometric system genuinely
                has nothing for this person that day."
            </p>

            <div class="form-group" style="position:relative;">
                <label class="import-label" for="check-emp-search">Employee</label>
                <input type="text" id="check-emp-search" autocomplete="off" placeholder="Search by name or EmpNo…">
                <input type="hidden" id="check-emp-id">
                <div id="check-emp-suggestions" style="display:none;position:absolute;z-index:20;width:100%;max-height:220px;overflow-y:auto;background:#fff;border:1px solid #cdd9e5;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 6px 14px rgba(31,45,61,0.08);"></div>
            </div>

            <div class="form-group" style="margin-top:0.75rem;">
                <label class="import-label" for="check-date">Date</label>
                <input type="date" id="check-date">
            </div>

            <div style="margin-top:1rem;">
                <button type="button" id="check-employee-btn" class="hrm-btn" disabled>
                    <i class="fa-solid fa-satellite-dish" style="margin-right:0.35rem;"></i>
                    Check Biometric Feed
                </button>
            </div>

            <div id="check-employee-result" style="margin-top:1rem;font-size:0.85rem;"></div>
        </div>

        {{-- ── UNRESOLVED / UNMATCHED PUNCHES ── --}}
        <div class="hris-table-card">
            <div class="hris-table-header">
                <div class="hris-table-header-title">
                    <h3 class="hris-table-title">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;font-size:1.1rem;"></i>
                        Unresolved / Unmatched Punches
                    </h3>
                    <p class="hris-table-subtitle">
                        DTR rows carrying a raw punch that was never placed into any time slot — the
                        fingerprint of a grouping bug, not an ordinary forgotten punch-out (those aren't
                        shown here). Recompute rebuilds the row from already-imported punches only; it
                        calls no external API and is safe to re-run. A punch already explained by an
                        approved Locator, Work Suspension, or DTR Excuse conflict — visible on the DTR
                        page itself — won't appear here either, since Recompute can never resolve those.
                    </p>
                </div>
            </div>

            <div class="hris-table-filters">
                <form method="GET" action="{{ route('hr-manager.attendance.import') }}"
                      id="unmatched-filter-form" class="hris-filter-left" style="align-items:flex-end;">
                    <div class="hris-filter-group">
                        <label class="hris-filter-label" for="unmatched_from">From</label>
                        <input type="date" id="unmatched_from" name="unmatched_from" value="{{ $unmatchedFrom }}" class="hris-filter-select">
                    </div>
                    <div class="hris-filter-group">
                        <label class="hris-filter-label" for="unmatched_to">To</label>
                        <input type="date" id="unmatched_to" name="unmatched_to" value="{{ $unmatchedTo }}" class="hris-filter-select">
                    </div>
                    <div class="hris-filter-group">
                        <label class="hris-filter-label" for="unmatched_dept_id">Department</label>
                        <select id="unmatched_dept_id" name="unmatched_dept_id" class="hris-filter-select">
                            <option value="">All Departments</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->Dept_id }}" {{ (string) $unmatchedDeptId === (string) $dept->Dept_id ? 'selected' : '' }}>
                                    {{ $dept->Dept_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="hris-filter-group">
                        <label class="hris-filter-label" for="unmatched_search">Search</label>
                        <input type="text" id="unmatched_search" name="unmatched_search" value="{{ $unmatchedSearch }}"
                               placeholder="Search by name or EmpNo…" class="hris-filter-select">
                    </div>
                    <button type="submit" class="hris-btn-secondary" style="align-self:flex-end;">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                </form>
            </div>

            <div id="unmatched-results">
                <div class="hris-empty-state">
                    <div class="hris-empty-state-icon"><i class="fa-solid fa-spinner fa-spin" style="color:#17a2b8;"></i></div>
                    <div class="hris-empty-state-title">Loading…</div>
                    <div class="hris-empty-state-text">Checking for unresolved punches — this cross-checks Locator/Excuse/Suspension records and can take a few seconds.</div>
                </div>
            </div>
        </div>

        </div>
    </div>
@endsection

@section('page_scripts')
    <script>
        (function () {
            const tabs = document.querySelectorAll('.import-tab-btn');
            const panels = document.querySelectorAll('.import-panel');

            function activate(id) {
                tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === id));
                panels.forEach(p => p.classList.toggle('active', p.id === 'tab-' + id));
                try { sessionStorage.setItem('hris_import_tab', id); } catch (_) {}
            }

            tabs.forEach(t => t.addEventListener('click', () => activate(t.dataset.tab)));

            const hash = window.location.hash.replace('#tab-', '');
            const saved = sessionStorage.getItem('hris_import_tab');
            const hasUnmatchedParams = /[?&](unmatched_from|unmatched_to|unmatched_dept_id|unmatched_search)=/.test(window.location.search);

            if (hash && document.getElementById('tab-' + hash)) {
                activate(hash);
            } else if (hasUnmatchedParams) {
                activate('diagnostics');
            } else if (saved && document.getElementById('tab-' + saved)) {
                activate(saved);
            }
        })();

        (function () {
            const fromEl = document.getElementById('from_date');
            const toEl   = document.getElementById('to_date');

            function fmt(d) {
                return [
                    d.getFullYear(),
                    String(d.getMonth() + 1).padStart(2, '0'),
                    String(d.getDate()).padStart(2, '0'),
                ].join('-');
            }

            document.querySelectorAll('[data-preset]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const today = new Date();
                    let from, to;

                    switch (btn.dataset.preset) {
                        case 'this-week': {
                            const dow = today.getDay() || 7; // treat Sun as 7
                            from = new Date(today); from.setDate(today.getDate() - dow + 1);
                            to   = new Date(today); to.setDate(today.getDate() - dow + 7);
                            break;
                        }
                        case 'this-month':
                            from = new Date(today.getFullYear(), today.getMonth(), 1);
                            to   = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                            break;
                        case 'last-month':
                            from = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                            to   = new Date(today.getFullYear(), today.getMonth(), 0);
                            break;
                        case 'last-7':
                            from = new Date(today); from.setDate(today.getDate() - 6);
                            to   = new Date(today);
                            break;
                        case 'last-30':
                            from = new Date(today); from.setDate(today.getDate() - 29);
                            to   = new Date(today);
                            break;
                    }

                    fromEl.value = fmt(from);
                    toEl.value   = fmt(to);
                });
            });
        })();

        (function () {
            const searchInput = document.getElementById('check-emp-search');
            const hiddenId    = document.getElementById('check-emp-id');
            const suggestions = document.getElementById('check-emp-suggestions');
            const dateInput   = document.getElementById('check-date');
            const checkBtn    = document.getElementById('check-employee-btn');
            const resultBox   = document.getElementById('check-employee-result');

            if (!searchInput) return;

            function escHtml(str) {
                const div = document.createElement('div');
                div.textContent = String(str ?? '');

                return div.innerHTML;
            }

            let debounceTimer = null;

            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                const q = this.value.trim();
                hiddenId.value = '';
                checkBtn.disabled = true;

                if (q.length < 2) {
                    suggestions.style.display = 'none';

                    return;
                }

                debounceTimer = setTimeout(() => {
                    fetch('{{ route("api.attendance-import.employee-search") }}?q=' + encodeURIComponent(q))
                        .then(r => r.json())
                        .then(items => {
                            if (!Array.isArray(items) || !items.length) {
                                suggestions.innerHTML = '<div class="check-emp-suggestion" style="cursor:default;color:#94a3b8;">No employees found.</div>';
                                suggestions.style.display = 'block';

                                return;
                            }

                            suggestions.innerHTML = items.map(emp => `
                                <button type="button" class="check-emp-suggestion" data-id="${emp.id}"
                                        data-name="${escHtml(emp.last_name + ', ' + emp.first_name)} (${escHtml(emp.EmpNo ?? '')})">
                                    ${escHtml(emp.last_name + ', ' + emp.first_name)}
                                    <small>${escHtml(emp.EmpNo ?? '')}${emp.department?.Dept_name ? ' &middot; ' + escHtml(emp.department.Dept_name) : ''}</small>
                                </button>
                            `).join('');
                            suggestions.style.display = 'block';
                        })
                        .catch(() => { suggestions.style.display = 'none'; });
                }, 250);
            });

            suggestions.addEventListener('click', function (e) {
                const btn = e.target.closest('[data-id]');
                if (!btn) return;

                hiddenId.value    = btn.dataset.id;
                searchInput.value = btn.dataset.name;
                suggestions.style.display = 'none';
                checkBtn.disabled = false;
            });

            document.addEventListener('click', function (e) {
                if (!e.target.closest('#check-emp-search') && !e.target.closest('#check-emp-suggestions')) {
                    suggestions.style.display = 'none';
                }
            });

            function renderPunchList(items) {
                if (!items.length) return '';

                return '<ul class="check-punch-list">' + items.map(item =>
                    `<li>${escHtml(item.logtime ?? '?')} ${escHtml(item.in_out ?? '')}</li>`
                ).join('') + '</ul>';
            }

            checkBtn.addEventListener('click', function () {
                if (!hiddenId.value || !dateInput.value) {
                    resultBox.innerHTML = '<div class="check-result-box check-result-error">Pick an employee and a date first.</div>';

                    return;
                }

                checkBtn.disabled = true;
                resultBox.innerHTML = '<div class="check-result-box check-result-notfound"><i class="fa-solid fa-spinner fa-spin"></i> Checking the biometric feed - this calls the external API directly and can take a few seconds…</div>';

                fetch('{{ route("hr-manager.attendance.import.check-employee") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ user_id: hiddenId.value, date: dateInput.value }),
                })
                    .then(async r => {
                        const data = await r.json();
                        if (!r.ok) throw new Error(data.error || 'Something went wrong.');

                        return data;
                    })
                    .then(data => {
                        const dbNote = data.already_in_attendance_logs.length
                            ? `Already in our database: ${data.already_in_attendance_logs.length} punch(es) for this date.`
                            : 'Not yet in our database for this date.';

                        if (data.matched_by_id.length) {
                            resultBox.innerHTML = `
                                <div class="check-result-box check-result-found">
                                    <strong><i class="fa-solid fa-circle-check"></i> Found under ${escHtml(data.emp_no)}'s own EmpNo.</strong>
                                    ${renderPunchList(data.matched_by_id)}
                                    <div style="margin-top:0.4rem;font-size:0.78rem;">${escHtml(dbNote)}</div>
                                </div>`;
                        } else if (data.matched_by_name_different_id.length) {
                            const foundIds = [...new Set(data.matched_by_name_different_id.map(i => i.personnelid))].join(', ');
                            resultBox.innerHTML = `
                                <div class="check-result-box check-result-warning">
                                    <strong><i class="fa-solid fa-triangle-exclamation"></i> Found under a DIFFERENT personnelid: ${escHtml(foundIds)}</strong>
                                    <div style="margin-top:0.3rem;">A name match was found in the feed, but not under ${escHtml(data.emp_no)}. This employee's EmpNo may be wrong in HRIS - double-check it against the biometric device's own roster.</div>
                                    ${renderPunchList(data.matched_by_name_different_id)}
                                </div>`;
                        } else {
                            resultBox.innerHTML = `
                                <div class="check-result-box check-result-notfound">
                                    <strong><i class="fa-solid fa-circle-info"></i> No record found for this employee on this date.</strong>
                                    <div style="margin-top:0.3rem;">Checked ${data.pages_checked} page(s), ${data.total_records_that_day} total company-wide punch record(s) that day - not under their EmpNo or their name. This is a gap in the source biometric data, not an import issue.</div>
                                    <div style="margin-top:0.4rem;font-size:0.78rem;">${escHtml(dbNote)}</div>
                                </div>`;
                        }
                    })
                    .catch(err => {
                        resultBox.innerHTML = `<div class="check-result-box check-result-error"><i class="fa-solid fa-circle-exclamation"></i> ${escHtml(err.message)}</div>`;
                    })
                    .finally(() => { checkBtn.disabled = false; });
            });
        })();

        (function () {
            // Recompute forms are re-rendered on every AJAX refresh of
            // #unmatched-results (see the lazy-load IIFE below) - a plain
            // per-node querySelectorAll binding at page-load time would miss
            // every one of them. Event delegation on the stable parent
            // container keeps this working with zero re-binding needed.
            const container = document.getElementById('unmatched-results');
            if (!container) return;

            container.addEventListener('submit', function (e) {
                const form = e.target.closest('.import-recompute-form');
                if (!form) return;

                e.preventDefault();
                const name = form.dataset.employee;
                const date = form.dataset.date;

                Swal.fire({
                    icon: 'warning',
                    title: 'Recompute this DTR?',
                    html: 'Rebuilds <b>' + name + '</b>&rsquo;s DTR from already-imported punches around ' + date + ' using the current computation logic. Does not call the external biometric API - safe to re-run.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, recompute',
                    confirmButtonColor: '#17a2b8',
                    cancelButtonColor: '#6b7280',
                }).then(function (res) {
                    if (res.isConfirmed) {
                        form.submit();
                    }
                });
            });
        })();

        (function () {
            // Lazy-loads the "Unresolved / Unmatched Punches" list - see
            // AttendanceImportController::unmatchedPunchesData()'s docblock
            // for why this moved off the default page-load path (~3.5s
            // pipeline vs. ~0.01s for everything else this page needs). Fires
            // once the first time the Diagnostics tab is actually opened,
            // or immediately on page load when a deep link already carries
            // unmatched_from/to/dept_id (preserving the existing auto-switch
            // behavior below).
            const resultsEl = document.getElementById('unmatched-results');
            const badgeEl = document.getElementById('unmatched-badge');
            const diagnosticsTabBtn = document.querySelector('.import-tab-btn[data-tab="diagnostics"]');
            const filterForm = document.getElementById('unmatched-filter-form');
            if (!resultsEl || !filterForm) return;

            let loaded = false;
            const FILTER_FIELD_NAMES = ['unmatched_from', 'unmatched_to', 'unmatched_dept_id', 'unmatched_search'];

            let currentPage = parseInt(new URLSearchParams(window.location.search).get('page'), 10) || 1;

            function currentParams() {
                const params = new URLSearchParams();
                FILTER_FIELD_NAMES.forEach(function (name) {
                    const el = document.getElementById(name);
                    if (el && el.value) params.set(name, el.value);
                });
                if (currentPage > 1) params.set('page', currentPage);

                return params;
            }

            function loadUnmatchedPunches() {
                const params = currentParams();
                resultsEl.innerHTML = '<div class="hris-empty-state">'
                    + '<div class="hris-empty-state-icon"><i class="fa-solid fa-spinner fa-spin" style="color:#17a2b8;"></i></div>'
                    + '<div class="hris-empty-state-title">Loading…</div>'
                    + '<div class="hris-empty-state-text">Checking for unresolved punches — this cross-checks Locator/Excuse/Suspension records and can take a few seconds.</div>'
                    + '</div>';

                fetch('{{ route("hr-manager.attendance.import.unmatched-data") }}?' + params.toString())
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        resultsEl.innerHTML = data.html;

                        if (badgeEl) {
                            if (data.badge_count > 0) {
                                badgeEl.style.display = '';
                                badgeEl.textContent = data.badge_capped ? (data.badge_count + '+') : data.badge_count;
                                badgeEl.title = 'Exact count';
                            } else {
                                badgeEl.style.display = 'none';
                            }
                        }
                    })
                    .catch(function () {
                        resultsEl.innerHTML = '<div class="hris-empty-state">'
                            + '<div class="hris-empty-state-icon"><i class="fa-solid fa-circle-exclamation" style="color:#b91c1c;"></i></div>'
                            + '<div class="hris-empty-state-title">Couldn\'t load this list</div>'
                            + '<div class="hris-empty-state-text">Try again, or reload the page.</div>'
                            + '</div>';
                    });
            }

            // The shared pagination component (see the results partial)
            // renders real anchor links (so they degrade gracefully if this
            // listener somehow doesn't attach), but clicking one should
            // update this same panel via AJAX rather than a full page reload
            // - intercept it, pull the target page out of the link's own
            // querystring, and re-fetch.
            resultsEl.addEventListener('click', function (e) {
                const link = e.target.closest('.hris-pagination-link[href]');
                if (!link) return;

                e.preventDefault();
                const targetPage = parseInt(new URL(link.href, window.location.href).searchParams.get('page'), 10) || 1;
                currentPage = targetPage;
                loaded = true;
                loadUnmatchedPunches();
            });

            function loadOnce() {
                if (loaded) return;
                loaded = true;
                loadUnmatchedPunches();
            }

            if (diagnosticsTabBtn) {
                diagnosticsTabBtn.addEventListener('click', loadOnce);
            }

            const hasUnmatchedParams = /[?&](unmatched_from|unmatched_to|unmatched_dept_id|unmatched_search|page)=/.test(window.location.search);
            const hash = window.location.hash.replace('#tab-', '');
            let savedTab = null;
            try { savedTab = sessionStorage.getItem('hris_import_tab'); } catch (_) {}

            if (hasUnmatchedParams || hash === 'diagnostics' || savedTab === 'diagnostics') {
                loadOnce();
            }

            filterForm.addEventListener('submit', function (e) {
                e.preventDefault();
                currentPage = 1; // a filter/search change always starts over from page 1
                const params = currentParams();

                try {
                    const url = new URL(window.location.href);
                    FILTER_FIELD_NAMES.concat('page').forEach(function (name) {
                        url.searchParams.delete(name);
                    });
                    params.forEach(function (value, name) { url.searchParams.set(name, value); });
                    url.hash = 'tab-diagnostics';
                    window.history.pushState({}, '', url);
                } catch (_) {}

                loaded = true;
                loadUnmatchedPunches();
            });

            // Live search - debounced, matching the 250ms pattern the "Check
            // Raw Biometric Feed" search on this same page already uses.
            // Filter still works too (same currentParams()), for From/To/
            // Department without needing to type into this field at all.
            const searchInput = document.getElementById('unmatched_search');
            if (searchInput) {
                let searchDebounce = null;
                searchInput.addEventListener('input', function () {
                    clearTimeout(searchDebounce);
                    searchDebounce = setTimeout(function () {
                        currentPage = 1; // a new search always starts over from page 1
                        loaded = true;
                        loadUnmatchedPunches();
                    }, 300);
                });
            }
        })();
    </script>
@endsection
