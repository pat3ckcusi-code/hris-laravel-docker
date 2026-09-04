@extends('dashboards.layout', [
    'title' => 'Import Attendance Logs',
    'subtitle' => 'Pull biometric punch logs from the integration API for a date range.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
    <style>
        .import-layout {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 1.5rem;
            margin-top: 1.25rem;
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
                        @endphp
                        <div style="font-size:0.78rem;padding:0.45rem 0.65rem;border-radius:6px;
                            background:{{ ($ok && $imported > 0) ? '#f0fdf4' : ($ok ? '#f8fafc' : '#fef2f2') }};
                            border:1px solid {{ ($ok && $imported > 0) ? '#bbf7d0' : ($ok ? '#e2e8f0' : '#fca5a5') }};">
                            <div style="font-weight:600;color:{{ ($ok && $imported > 0) ? '#15803d' : ($ok ? '#64748b' : '#b91c1c') }};">
                                {{ $ok ? ($imported > 0 ? '✓' : '○') : '✗' }}
                                {{ $d['from'] ?? '?' }}–{{ $d['to'] ?? '?' }}
                                &nbsp;{{ $imported }} in / {{ $skipped }} skipped
                            </div>
                            @if($err)
                                <div style="color:#7f1d1d;margin-top:0.2rem;word-break:break-word;">{{ $err }}</div>
                            @endif
                            @foreach($msgs as $msg)
                                <div style="color:#475569;margin-top:0.15rem;">{{ $msg }}</div>
                            @endforeach
                            @if($hasUnmatched)
                                <div style="color:#94a3b8;margin-top:0.15rem;font-style:italic;">
                                    Unmatched EmpNo checks run company-wide and are not limited to the scope below.
                                </div>
                            @endif
                            <div style="color:#94a3b8;margin-top:0.2rem;">
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

    {{-- ── DIAGNOSTIC: check raw biometric feed for one employee/date ── --}}
    <div class="import-form-card" style="margin-top:1.5rem;max-width:640px;position:relative;">
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
    <div class="import-form-card" style="margin-top:1.5rem;">
        <h3><i class="fa-solid fa-triangle-exclamation"></i> Unresolved / Unmatched Punches</h3>
        <p class="import-subtitle">
            DTR rows carrying a raw punch that was never placed into any time slot - the
            fingerprint of a grouping bug, not an ordinary forgotten punch-out (those aren't
            shown here). Recompute rebuilds the row from already-imported punches only; it
            calls no external API and is safe to re-run.
        </p>

        <form method="GET" action="{{ route('hr-manager.attendance.import') }}"
              style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem;">
            <div class="form-group">
                <label class="import-label" for="unmatched_from">From</label>
                <input type="date" id="unmatched_from" name="unmatched_from" value="{{ $unmatchedFrom }}">
            </div>
            <div class="form-group">
                <label class="import-label" for="unmatched_to">To</label>
                <input type="date" id="unmatched_to" name="unmatched_to" value="{{ $unmatchedTo }}">
            </div>
            <div class="form-group">
                <label class="import-label" for="unmatched_dept_id">Department</label>
                <select id="unmatched_dept_id" name="unmatched_dept_id">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->Dept_id }}" {{ (string) $unmatchedDeptId === (string) $dept->Dept_id ? 'selected' : '' }}>
                            {{ $dept->Dept_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="hrm-btn">
                <i class="fa-solid fa-filter" style="margin-right:0.35rem;"></i> Filter
            </button>
        </form>

        @if($unmatchedDtrs->isEmpty())
            <p style="font-size:0.85rem;color:#6c757d;">
                <i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:0.3rem;"></i>
                No unresolved punches found in this range.
            </p>
        @else
            <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:2px solid #e2ecf5;">
                        <th style="padding:0.5rem;">Employee</th>
                        <th style="padding:0.5rem;">Department</th>
                        <th style="padding:0.5rem;">Date</th>
                        <th style="padding:0.5rem;">Status</th>
                        <th style="padding:0.5rem;">Unmatched Punch(es)</th>
                        <th style="padding:0.5rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($unmatchedDtrs as $row)
                        @php
                            $emp = $row->employee;
                            $empName = $emp ? trim("{$emp->last_name}, {$emp->first_name}") : "Employee #{$row->employee_id}";
                        @endphp
                        <tr style="border-bottom:1px solid #eef2f7;">
                            <td style="padding:0.5rem;">{{ $empName }} <span style="color:#94a3b8;">({{ $emp->EmpNo ?? '—' }})</span></td>
                            <td style="padding:0.5rem;">{{ $emp?->department?->Dept_name ?? '—' }}</td>
                            <td style="padding:0.5rem;">{{ $row->date->format('M j, Y') }}</td>
                            <td style="padding:0.5rem;">{{ ucwords(str_replace('_', ' ', $row->status ?? 'present')) }}</td>
                            <td style="padding:0.5rem;">
                                @foreach($row->unmatched_logs as $t)
                                    <span style="display:inline-block;background:#fef9c3;color:#854d0e;border-radius:4px;padding:0.1rem 0.4rem;font-size:0.78rem;margin:0 0.2rem 0.2rem 0;">{{ substr($t, 0, 5) }}</span>
                                @endforeach
                            </td>
                            <td style="padding:0.5rem;">
                                <form method="POST" action="{{ route('hr-manager.attendance.import.recompute-unmatched') }}"
                                      class="import-recompute-form"
                                      data-employee="{{ $empName }}" data-date="{{ $row->date->format('M j, Y') }}">
                                    @csrf
                                    <input type="hidden" name="employee_id" value="{{ $row->employee_id }}">
                                    <input type="hidden" name="date" value="{{ $row->date->toDateString() }}">
                                    <button type="submit" class="hrm-btn" style="padding:0.3rem 0.65rem;font-size:0.78rem;">
                                        <i class="fa-solid fa-arrows-rotate"></i> Recompute
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @if($unmatchedDtrs->count() >= 300)
                <p style="font-size:0.78rem;color:#94a3b8;margin-top:0.5rem;">Showing the first 300 matching rows - narrow the date range to see more.</p>
            @endif
        @endif
    </div>
@endsection

@section('page_scripts')
    <script>
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
            document.querySelectorAll('.import-recompute-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
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
            });
        })();
    </script>
@endsection
