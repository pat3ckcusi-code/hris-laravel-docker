@extends('dashboards.layout', [
    'title'    => 'OIC Assignments',
    'subtitle' => 'Delegate department authority with time-bound access',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('tiles')
    @php
        $today         = now()->toDateString();
        $activeCount   = $assignments->filter(fn($a) => $a->start_date->toDateString() <= $today && $a->end_date->toDateString() >= $today)->count();
        $upcomingCount = $assignments->filter(fn($a) => $a->start_date->toDateString() > $today)->count();
        $expiredCount  = $assignments->filter(fn($a) => $a->end_date->toDateString() < $today)->count();
    @endphp

    <article class="kpi-card accent-overtime">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-user-check"></i></div>
                <div class="kpi-title">Active OICs</div>
            </div>
            <div class="kpi-meta">Currently acting in role</div>
        </div>
        <div class="kpi-value">{{ $activeCount }}</div>
    </article>

    <article class="kpi-card accent-attendance">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-calendar-plus"></i></div>
                <div class="kpi-title">Upcoming</div>
            </div>
            <div class="kpi-meta">Scheduled appointments</div>
        </div>
        <div class="kpi-value">{{ $upcomingCount }}</div>
    </article>

    <article class="kpi-card">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" style="background:linear-gradient(180deg,#94a3b8,#64748b);" aria-hidden="true">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div class="kpi-title">Expired</div>
            </div>
            <div class="kpi-meta">Past assignments</div>
        </div>
        <div class="kpi-value">{{ $expiredCount }}</div>
    </article>
@endsection

@section('content')

{{-- ── What is OIC? ─────────────────────────────────────────────────────────── --}}
<div style="
    display:flex;align-items:flex-start;gap:14px;
    background:#f0f9ff;border:1px solid #bae6fd;border-left:4px solid #0ea5e9;
    border-radius:10px;padding:14px 18px;margin-bottom:24px;
">
    <i class="fa-solid fa-circle-info" style="color:#0ea5e9;font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
    <div>
        <strong style="color:#0c4a6e;font-size:0.92rem;">About OIC Assignments</strong>
        <p style="margin:4px 0 0;font-size:0.875rem;color:#1e3a4c;line-height:1.55;">
            Appoint an Officer-in-Charge when you will be on leave. The designated employee inherits
            your approval authority — leave, ETA, and locator requests — only during the assigned period.
            Access expires automatically on the end date.
        </p>
    </div>
</div>

{{-- ── Appoint Form (real dept heads/AOs only) ──────────────────────────────── --}}
@if($canAppoint)
<div class="hris-table-card" style="margin-bottom:24px;">
    <div class="hris-table-header" style="background:linear-gradient(90deg,#fff7ed,#fff);">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-user-plus" style="color:#ea580c;margin-right:8px;"></i>Appoint an OIC
            </h2>
            <p class="hris-table-subtitle">Select an employee from your department, specify the role and coverage dates.</p>
        </div>
    </div>

    <div style="padding:20px 24px 24px;">
        @if($departments->isEmpty())
            <div class="hris-empty-state">
                <div class="hris-empty-state-icon"><i class="fa-solid fa-building-circle-exclamation"></i></div>
                <div class="hris-empty-state-title">No Department Found</div>
                <div class="hris-empty-state-text">Your account is not linked to a department. Contact the Records Manager to set up your Employee Number.</div>
            </div>
        @else
        <form method="POST" action="{{ route('department-head.oic-assignments.store') }}" id="oic-form" class="pds-form">
            @csrf

            <div class="field-grid three">
                <label>
                    Department
                    <select class="form-input" id="dept_id" name="dept_id" required>
                        <option value="">— Select Department —</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->Dept_id }}" {{ old('dept_id') == $dept->Dept_id ? 'selected' : '' }}>
                                {{ $dept->Dept_name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    OIC Employee
                    <select class="form-input" id="user_id" name="user_id" required>
                        <option value="">— Select employee —</option>
                        @foreach($departments as $dept)
                            @foreach(($employeesByDept[$dept->Dept_id] ?? []) as $emp)
                                @php $empName = $emp->name ?: trim(($emp->first_name ?? '') . ' ' . ($emp->last_name ?? '')); @endphp
                                <option value="{{ $emp->id }}"
                                    data-dept="{{ $dept->Dept_id }}"
                                    {{ old('user_id') == $emp->id ? 'selected' : '' }}>
                                    {{ $empName }}{{ $emp->designation ? ' — ' . $emp->designation : '' }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                </label>

                <label>
                    Role to Grant
                    <div class="form-input" style="display:flex;align-items:center;gap:8px;background:#f8fafc;cursor:default;">
                        <i class="fa-solid {{ $appointingRole === 'department head' ? 'fa-sitemap' : 'fa-user-tie' }}"
                           style="color:{{ $appointingRole === 'department head' ? '#0891b2' : '#7c3aed' }};"></i>
                        <span style="font-weight:600;color:#0f172a;">{{ ucwords($appointingRole) }}</span>
                        <span style="font-size:0.78rem;color:#94a3b8;margin-left:4px;">(inherited from your role)</span>
                    </div>
                    <input type="hidden" name="role" value="{{ $appointingRole }}">
                </label>
            </div>

            <div class="field-grid two" style="max-width:580px;">
                <label>
                    Start Date
                    <input class="form-input" type="date" id="start_date" name="start_date"
                           value="{{ old('start_date', date('Y-m-d')) }}" min="{{ date('Y-m-d') }}" required>
                </label>
                <label>
                    End Date
                    <input class="form-input" type="date" id="end_date" name="end_date"
                           value="{{ old('end_date') }}" min="{{ date('Y-m-d') }}" required>
                </label>
            </div>

            <div style="margin-top:6px;">
                <button type="submit" class="hris-btn hris-btn-primary">
                    <i class="fa-solid fa-user-plus"></i> Appoint OIC
                </button>
            </div>
        </form>
        @endif
    </div>
</div>
@else
{{-- OIC users see a notice instead of the appoint form --}}
<div style="
    display:flex;align-items:flex-start;gap:14px;
    background:#fefce8;border:1px solid #fde68a;border-left:4px solid #f59e0b;
    border-radius:10px;padding:14px 18px;margin-bottom:24px;
">
    <i class="fa-solid fa-shield-halved" style="color:#f59e0b;font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
    <div>
        <strong style="color:#78350f;font-size:0.92rem;">View Only — OIC Access</strong>
        <p style="margin:4px 0 0;font-size:0.875rem;color:#451a03;line-height:1.55;">
            You are currently acting as an Officer-in-Charge. Only the original Department Head or
            Administrative Officer can appoint or cancel OIC assignments.
        </p>
    </div>
</div>
@endif

{{-- ── Assignments Table ─────────────────────────────────────────────────────── --}}
<div class="hris-table-card">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-list-check" style="color:#0891b2;margin-right:8px;"></i>OIC Assignment Records
            </h2>
            <p class="hris-table-subtitle">All current, upcoming, and past OIC appointments for your department.</p>
        </div>
    </div>

    @if($assignments->isEmpty())
        <div class="hris-empty-state">
            <div class="hris-empty-state-icon"><i class="fa-solid fa-user-clock"></i></div>
            <div class="hris-empty-state-title">No Assignments Yet</div>
            <div class="hris-empty-state-text">Use the form above to appoint an OIC before going on leave.</div>
        </div>
    @else
    <div class="hris-table-wrapper">
        <table class="hris-table" style="width:100%">
            <thead>
                <tr>
                    <th>OIC Employee</th>
                    <th>Department</th>
                    <th>Role Granted</th>
                    <th>Coverage Period</th>
                    <th>Status</th>
                    <th>Appointed By</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($assignments as $a)
                    @php
                        $isActive = $a->start_date->toDateString() <= $today && $a->end_date->toDateString() >= $today;
                        $isPast   = $a->end_date->toDateString() < $today;
                        $isFuture = $a->start_date->toDateString() > $today;

                        $daysLeft = $isActive
                            ? $a->end_date->diffInDays(now()) + 1
                            : ($isFuture ? $a->start_date->diffInDays(now()) : 0);
                    @endphp
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="
                                    width:34px;height:34px;border-radius:50%;flex-shrink:0;
                                    background:{{ $isActive ? '#dcfce7' : ($isFuture ? '#dbeafe' : '#f1f5f9') }};
                                    display:grid;place-items:center;
                                    color:{{ $isActive ? '#166534' : ($isFuture ? '#1d4ed8' : '#94a3b8') }};
                                    font-size:0.85rem;font-weight:700;
                                ">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:0.875rem;color:#0f172a;">{{ $a->user?->name ?: '—' }}</div>
                                    @if($a->user?->designation)
                                        <div style="font-size:0.78rem;color:#64748b;">{{ $a->user->designation }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td style="font-size:0.875rem;">{{ $a->department?->Dept_name ?: '—' }}</td>
                        <td>
                            <span class="badge {{ $a->role === 'department head' ? 'badge-approved' : 'badge-pending' }}"
                                  style="font-size:0.75rem;">
                                <i class="fa-solid {{ $a->role === 'department head' ? 'fa-sitemap' : 'fa-user-tie' }}" style="margin-right:4px;"></i>
                                {{ ucwords($a->role) }}
                            </span>
                        </td>
                        <td>
                            <div style="font-size:0.875rem;color:#1e293b;">
                                {{ $a->start_date->format('M d') }} – {{ $a->end_date->format('M d, Y') }}
                            </div>
                            @if($isActive)
                                <div style="font-size:0.78rem;color:#16a34a;margin-top:2px;">
                                    <i class="fa-solid fa-circle" style="font-size:0.5rem;vertical-align:middle;"></i>
                                    {{ $daysLeft }} day{{ $daysLeft != 1 ? 's' : '' }} remaining
                                </div>
                            @elseif($isFuture)
                                <div style="font-size:0.78rem;color:#2563eb;margin-top:2px;">
                                    Starts in {{ $daysLeft }} day{{ $daysLeft != 1 ? 's' : '' }}
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($isActive)
                                <span class="badge badge-active">Active</span>
                            @elseif($isFuture)
                                <span class="badge badge-pending">Upcoming</span>
                            @else
                                <span class="badge badge-cancelled">Expired</span>
                            @endif
                        </td>
                        <td style="font-size:0.875rem;color:#475569;">{{ $a->appointedBy?->name ?: '—' }}</td>
                        <td style="text-align:center;">
                            @if($canAppoint && !$isPast)
                                <button type="button"
                                        class="hris-btn hris-btn-danger hris-btn-sm cancel-oic-btn"
                                        data-id="{{ $a->id }}"
                                        data-name="{{ $a->user?->name }}"
                                        data-action="{{ route('department-head.oic-assignments.destroy', $a->id) }}">
                                    <i class="fa-solid fa-ban"></i> Cancel
                                </button>
                            @else
                                <span style="color:#cbd5e1;font-size:0.8rem;">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- Hidden delete form, submitted by JS ──────────────────────────────────────── --}}
<form id="cancel-oic-form" method="POST" style="display:none;">
    @csrf
    @method('DELETE')
</form>

@endsection

@section('page_scripts')
<script>
(function () {

    /* ── Employee dropdown filter by department ──────────────────────────── */
    const deptSelect = document.getElementById('dept_id');
    const empSelect  = document.getElementById('user_id');
    const startInput = document.getElementById('start_date');
    const endInput   = document.getElementById('end_date');

    if (deptSelect && empSelect) {
        const allOptions = Array.from(empSelect.querySelectorAll('option[data-dept]'));

        function filterEmployees() {
            const selected = deptSelect.value;
            const current  = empSelect.value;
            empSelect.innerHTML = '<option value="">— Select employee —</option>';
            allOptions.forEach(opt => {
                if (!selected || opt.dataset.dept === selected) {
                    empSelect.appendChild(opt.cloneNode(true));
                }
            });
            empSelect.value = current;
        }

        deptSelect.addEventListener('change', filterEmployees);
        filterEmployees();
    }

    /* ── Date range guard ────────────────────────────────────────────────── */
    if (startInput && endInput) {
        startInput.addEventListener('change', function () {
            endInput.min = startInput.value;
            if (endInput.value && endInput.value < startInput.value) {
                endInput.value = startInput.value;
            }
        });
    }

    /* ── Cancel OIC (SweetAlert2 confirm) ────────────────────────────────── */
    document.querySelectorAll('.cancel-oic-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const name   = btn.dataset.name || 'this employee';
            const action = btn.dataset.action;

            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cancel OIC Assignment?',
                    html: '<p style="margin:0;font-size:0.95rem;">This will immediately revoke <strong>' + name + '</strong>\'s temporary access.</p>',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, cancel it',
                    cancelButtonText: 'Keep assignment',
                    confirmButtonColor: '#dc2626',
                }).then(function (result) {
                    if (!result.isConfirmed) return;
                    submitCancel(action);
                });
            } else {
                if (confirm('Cancel OIC assignment for ' + name + '?')) {
                    submitCancel(action);
                }
            }
        });
    });

    function submitCancel(action) {
        const form = document.getElementById('cancel-oic-form');
        form.action = action;
        form.submit();
    }

    /* ── Flash messages via SweetAlert2 ─────────────────────────────────── */
    @if(session('success'))
    try {
        if (window.Swal) {
            Swal.fire({ icon: 'success', title: 'Done', text: {!! json_encode(session('success')) !!}, timer: 2800, showConfirmButton: false });
        }
    } catch (e) {}
    @endif

    @if(session('error'))
    try {
        if (window.Swal) {
            Swal.fire({ icon: 'error', title: 'Error', text: {!! json_encode(session('error')) !!} });
        }
    } catch (e) {}
    @endif

    @if(!empty($errors) && $errors->any())
    try {
        if (window.Swal) {
            Swal.fire({ icon: 'error', title: 'Validation Error', text: {!! json_encode($errors->first()) !!} });
        }
    } catch (e) {}
    @endif

})();
</script>
@endsection
