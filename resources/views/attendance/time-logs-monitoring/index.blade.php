@extends('dashboards.layout', [
    'title' => 'Time Logs Monitoring',
    'subtitle' => 'Which department has the most lateness/undertime, and which employees cross the CSC habitual-tardiness threshold.',
])

@section('page_head')
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<style>
.tlm-rank {
    display:inline-flex; align-items:center; justify-content:center;
    width:1.7rem; height:1.7rem; border-radius:50%; flex:0 0 auto;
    background:#f1f5f9; color:#475569; font-size:.72rem; font-weight:700;
}
.tlm-rank-1 { background:#fee2e2; color:#991b1b; }
.tlm-rank-2 { background:#ffedd5; color:#9a3412; }
.tlm-rank-3 { background:#fef9c3; color:#854d0e; }
.tlm-dept-name { font-weight:600; color:#0f172a; }
.tlm-count-badge {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.3rem .65rem; border-radius:9999px; font-size:.78rem; font-weight:700;
}
.tlm-count-zero { background:#f1f5f9; color:#94a3b8; font-weight:600; }
.tlm-count-hot { background:#fee2e2; color:#991b1b; cursor:pointer; }
.tlm-count-hot:hover { background:#fecaca; }
.tlm-officer { display:flex; align-items:center; gap:.55rem; }
.tlm-avatar {
    display:inline-flex; align-items:center; justify-content:center;
    width:1.9rem; height:1.9rem; border-radius:50%; flex:0 0 auto;
    background:#eef2ff; color:#4338ca; font-size:.68rem; font-weight:700;
}
.tlm-flag-badge {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.3rem .65rem; border-radius:9999px; font-size:.72rem; font-weight:600;
    white-space:nowrap;
}
.tlm-month-chip {
    display:inline-block; padding:.15rem .5rem; border-radius:.35rem;
    font-size:.7rem; font-weight:600; margin:0 .2rem .2rem 0;
    background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;
}
.tlm-clickable-chip { cursor:pointer; }
.tlm-clickable-chip:hover { filter:brightness(0.95); text-decoration:underline; }
.tlm-stat-strip { display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:1rem; }
.tlm-stat-card {
    flex:1; min-width:11rem; background:#fff; border-radius:.75rem;
    box-shadow:0 1px 3px rgba(0,0,0,.1); padding:1rem 1.25rem;
    display:flex; align-items:center; gap:.85rem;
}
.tlm-stat-icon {
    width:2.5rem; height:2.5rem; border-radius:.6rem; flex:0 0 auto;
    display:inline-flex; align-items:center; justify-content:center; font-size:1rem;
}
.tlm-stat-value { font-size:1.35rem; font-weight:700; color:#0f172a; line-height:1.1; }
.tlm-stat-label { font-size:.75rem; color:#64748b; margin-top:.15rem; }
.tlm-notice-badge {
    display:inline-flex; flex-direction:column; gap:.1rem;
    padding:.35rem .65rem; border-radius:.5rem; font-size:.72rem; font-weight:600;
    background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; cursor:help;
}
.tlm-notice-badge small { font-weight:500; color:#047857; font-size:.66rem; }
</style>
@endsection

@php
    $monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $ordinals = [1 => '1st', 2 => '2nd', 3 => '3rd'];
    $anyFilterActive = $deptId || $employeeType || $deptSearch !== '' || $violationSearch !== '' || $violationSort !== 'name_asc';
@endphp

@section('content')

{{-- Stat strip --}}
<div class="tlm-stat-strip">
    <div class="tlm-stat-card">
        <span class="tlm-stat-icon" style="background:#eff6ff;color:#1d4ed8;"><i class="fas fa-building"></i></span>
        <div>
            <div class="tlm-stat-value">{{ $deptRanking->total() }}</div>
            <div class="tlm-stat-label">Departments Monitored</div>
        </div>
    </div>
    <div class="tlm-stat-card">
        <span class="tlm-stat-icon" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-user-clock"></i></span>
        <div>
            <div class="tlm-stat-value">{{ $violations->total() }}</div>
            <div class="tlm-stat-label">Employees Flagged in {{ $year }}</div>
        </div>
    </div>
    <div class="tlm-stat-card">
        <span class="tlm-stat-icon" style="background:#f5f3ff;color:#5b21b6;"><i class="fas fa-calendar-alt"></i></span>
        <div>
            <div class="tlm-stat-value">{{ \Carbon\Carbon::createFromDate($year, $month, 1)->format('M Y') }}</div>
            <div class="tlm-stat-label">Ranking Period</div>
        </div>
    </div>
</div>

{{-- Department Ranking --}}
<div class="hris-table-card">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title"><i class="fas fa-ranking-star" style="color:#ea580c;margin-right:.5rem;"></i>Department Ranking</h2>
            <p class="hris-table-subtitle">
                Ranked by combined tardiness + undertime days for {{ \Carbon\Carbon::createFromDate($year, $month, 1)->format('F Y') }}.
                @if ($deptSearch !== '')
                    Filtered by &ldquo;{{ $deptSearch }}&rdquo;.
                @endif
            </p>
        </div>
    </div>

    <div class="hris-table-wrapper">
        <table class="hris-table">
            <thead>
                <tr>
                    <th style="width:3rem;">#</th>
                    <th style="text-align:left;">Department</th>
                    <th>Employees</th>
                    <th>Tardiness Days</th>
                    <th>Undertime Days</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($deptRanking as $i => $d)
                    @php $rank = $deptRanking->firstItem() + $i; @endphp
                    <tr>
                        <td>
                            <span class="tlm-rank {{ $rank <= 3 ? 'tlm-rank-'.$rank : '' }}">{{ $rank }}</span>
                        </td>
                        <td style="text-align:left;">
                            <span class="tlm-dept-name"><i class="fas fa-building" style="color:#94a3b8;margin-right:.4rem;font-size:.8rem;"></i>{{ $d->dept_name }}</span>
                        </td>
                        <td>{{ $d->employee_count }}</td>
                        <td>
                            @if ($d->tardiness_count > 0)
                                <span class="tlm-count-badge tlm-count-hot breakdown-cell"
                                      data-dept="{{ $d->dept_name }}" data-label="Tardiness" data-unit="day(s) late"
                                      data-employees='@json($tardinessBreakdown->get($d->dept_id, collect()))'
                                      title="Click to view employees">
                                    <i class="fas fa-clock"></i> {{ $d->tardiness_count }}
                                </span>
                            @else
                                <span class="tlm-count-badge tlm-count-zero">0</span>
                            @endif
                        </td>
                        <td>
                            @if ($d->undertime_count > 0)
                                <span class="tlm-count-badge tlm-count-hot breakdown-cell"
                                      data-dept="{{ $d->dept_name }}" data-label="Undertime" data-unit="day(s) undertime"
                                      data-employees='@json($undertimeBreakdown->get($d->dept_id, collect()))'
                                      title="Click to view employees">
                                    <i class="fas fa-hourglass-half"></i> {{ $d->undertime_count }}
                                </span>
                            @else
                                <span class="tlm-count-badge tlm-count-zero">0</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="hris-empty-state">
                                <div class="hris-empty-state-icon"><i class="fas fa-building"></i></div>
                                <div class="hris-empty-state-title">No Departments Found</div>
                                <p class="hris-empty-state-text">
                                    @if ($deptSearch !== '')
                                        No departments match your search.
                                    @else
                                        No department data available.
                                    @endif
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:.75rem 1.25rem;">
        {{ $deptRanking->links() }}
    </div>
</div>

{{-- CSC Habitual Violations --}}
<div class="hris-table-card">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title"><i class="fas fa-triangle-exclamation" style="color:#ea580c;margin-right:.5rem;"></i>CSC Habitual Violations - {{ $year }}</h2>
            <p class="hris-table-subtitle">
                Per CSC MC No. 04, s. 1991: late 10+ times in a month, in at least 2 months within a semester (Jan&ndash;Jun / Jul&ndash;Dec) or 2 consecutive months.
                The same threshold is mirrored for undertime as &ldquo;Frequent Undertime&rdquo; - not an official separate CSC category.
                @if ($violationSearch !== '')
                    Filtered by &ldquo;{{ $violationSearch }}&rdquo;.
                @endif
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('attendance.time-logs-monitoring') }}">
        <div class="hris-table-filters hris-filters-sticky">
            <div class="hris-filter-left" style="flex-wrap:wrap;">
                <div class="hris-filter-group">
                    <label class="hris-filter-label" for="dept_id">Department</label>
                    <select id="dept_id" name="dept_id" class="hris-filter-select">
                        <option value="">All Departments</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->Dept_id }}" @selected($deptId === (int) $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="hris-filter-group">
                    <label class="hris-filter-label" for="month">Month</label>
                    <select id="month" name="month" class="hris-filter-select">
                        @foreach (range(1, 12) as $m)
                            <option value="{{ $m }}" @selected($m === $month)>{{ \Carbon\Carbon::createFromDate(null, $m, 1)->format('F') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="hris-filter-group">
                    <label class="hris-filter-label" for="year">Year</label>
                    <select id="year" name="year" class="hris-filter-select">
                        @foreach (range((int) date('Y') - 2, (int) date('Y') + 1) as $y)
                            <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="hris-filter-group">
                    <label class="hris-filter-label" for="employee_type">Employee Type</label>
                    <select id="employee_type" name="employee_type" class="hris-filter-select">
                        <option value="">All Types</option>
                        <option value="permanent" @selected($employeeType === 'permanent')>Permanent</option>
                        <option value="elected officials" @selected($employeeType === 'elected officials')>Elected Officials</option>
                        <option value="co-terminus" @selected($employeeType === 'co-terminus')>Co-Terminus</option>
                        <option value="casual" @selected($employeeType === 'casual')>Casual</option>
                        <option value="job orders" @selected($employeeType === 'job orders')>Job Orders</option>
                        <option value="contractual" @selected($employeeType === 'contractual')>Contractual</option>
                    </select>
                </div>
                <div class="hris-filter-group">
                    <label class="hris-filter-label" for="dept_search">Search Department</label>
                    <input type="text" id="dept_search" name="dept_search" value="{{ $deptSearch }}" placeholder="Department name" class="hris-filter-select">
                </div>
                <div class="hris-filter-group">
                    <label class="hris-filter-label" for="violation_search">Search Employee</label>
                    <input type="text" id="violation_search" name="violation_search" value="{{ $violationSearch }}" placeholder="Employee name" class="hris-filter-select">
                </div>
                <div class="hris-filter-group">
                    <label class="hris-filter-label" for="violation_sort">Order By</label>
                    <select id="violation_sort" name="violation_sort" class="hris-filter-select">
                        <option value="name_asc" @selected($violationSort === 'name_asc')>Employee Name (A-Z)</option>
                        <option value="name_desc" @selected($violationSort === 'name_desc')>Employee Name (Z-A)</option>
                        <option value="count_desc" @selected($violationSort === 'count_desc')>Most Violation Months First</option>
                        <option value="count_asc" @selected($violationSort === 'count_asc')>Fewest Violation Months First</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="hris-btn hris-btn-primary">
                    <i class="fas fa-search"></i> View
                </button>
                @if ($anyFilterActive)
                    <a href="{{ route('attendance.time-logs-monitoring') }}" class="hris-btn hris-btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="hris-table-wrapper">
        <table class="hris-table">
            <thead>
                <tr>
                    <th style="text-align:left;">Employee</th>
                    <th style="text-align:left;">Department</th>
                    <th>Flag</th>
                    <th style="text-align:left;">Tardy Months (10+/mo)</th>
                    <th style="text-align:left;">Undertime Months (10+/mo)</th>
                    <th style="text-align:left;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($violations as $v)
                    @php
                        $empName = $v['employee'] ? trim("{$v['employee']->last_name}, {$v['employee']->first_name}") : null;
                        $initials = $v['employee'] ? strtoupper(substr($v['employee']->first_name, 0, 1).substr($v['employee']->last_name, 0, 1)) : '-';
                    @endphp
                    <tr>
                        <td style="text-align:left;">
                            <div class="tlm-officer">
                                <span class="tlm-avatar">{{ $initials }}</span>
                                <span style="font-weight:600;color:#0f172a;">{{ $empName ?? '-' }}</span>
                            </div>
                        </td>
                        <td style="text-align:left;">{{ $v['employee']?->department?->Dept_name ?? '-' }}</td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:.3rem;align-items:flex-start;">
                                @if ($v['habitual_tardy'])
                                    <span class="tlm-flag-badge" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-clock"></i> Habitual Tardiness</span>
                                @endif
                                @if ($v['frequent_undertime'])
                                    <span class="tlm-flag-badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-hourglass-half"></i> Frequent Undertime</span>
                                @endif
                            </div>
                        </td>
                        <td style="text-align:left;">
                            @forelse ($v['tardy_months'] as $m)
                                <span class="tlm-month-chip tlm-clickable-chip"
                                      data-employee="{{ $empName ?? 'Unknown' }}"
                                      data-month-label="{{ $monthNames[$m] }} {{ $year }}"
                                      data-violation-type="Tardiness"
                                      data-dates='@json($v['tardy_dates_by_month']->get($m, collect()))'>{{ $monthNames[$m] }}</span>
                            @empty
                                <span style="color:#cbd5e1;">&mdash;</span>
                            @endforelse
                        </td>
                        <td style="text-align:left;">
                            @forelse ($v['undertime_months'] as $m)
                                <span class="tlm-month-chip tlm-clickable-chip" style="background:#fef9c3;color:#854d0e;border-color:#fde047;"
                                      data-employee="{{ $empName ?? 'Unknown' }}"
                                      data-month-label="{{ $monthNames[$m] }} {{ $year }}"
                                      data-violation-type="Undertime"
                                      data-dates='@json($v['undertime_dates_by_month']->get($m, collect()))'>{{ $monthNames[$m] }}</span>
                            @empty
                                <span style="color:#cbd5e1;">&mdash;</span>
                            @endforelse
                        </td>
                        <td style="text-align:left;">
                            <div style="display:flex;flex-direction:column;gap:.5rem;align-items:flex-start;">
                                @if ($v['employee'])
                                @if ($v['habitual_tardy'])
                                    @if ($v['tardy_notice'])
                                        @php
                                            $tn = $v['tardy_notice'];
                                            $tnIssuer = trim(($tn->issuer->first_name ?? '').' '.($tn->issuer->last_name ?? '')) ?: ($tn->issuer->name ?? 'Unknown');
                                            $tnSanction = \App\Models\HabitualViolationNotice::OFFENSE_SANCTIONS[$tn->offense_number] ?? $tn->offense_number;
                                        @endphp
                                        <span class="tlm-notice-badge" title="{{ \App\Models\HabitualViolationNotice::LEGAL_BASIS[\App\Models\HabitualViolationNotice::VIOLATION_TARDY] }}">
                                            <span><i class="fas fa-check-circle"></i> Tardiness: {{ $ordinals[$tn->offense_number] ?? $tn->offense_number }} Offense - {{ $tnSanction }}</span>
                                            <small>issued by {{ $tnIssuer }} on {{ $tn->created_at->format('M j, Y') }}</small>
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('attendance.time-logs-monitoring.issue-notice') }}"
                                              class="tlm-notice-form" data-violation-label="Habitual Tardiness"
                                              data-employee="{{ $empName }}"
                                              data-legal-basis="{{ \App\Models\HabitualViolationNotice::LEGAL_BASIS[\App\Models\HabitualViolationNotice::VIOLATION_TARDY] }}">
                                            @csrf
                                            <input type="hidden" name="employee_id" value="{{ $v['employee']->id }}">
                                            <input type="hidden" name="violation_type" value="{{ \App\Models\HabitualViolationNotice::VIOLATION_TARDY }}">
                                            <input type="hidden" name="year" value="{{ $year }}">
                                            <button type="submit" class="hris-btn hris-btn-warning hris-btn-sm">
                                                <i class="fas fa-file-circle-exclamation"></i> Issue Notice (Tardiness)
                                            </button>
                                        </form>
                                    @endif
                                @endif
                                @if ($v['frequent_undertime'])
                                    @if ($v['undertime_notice'])
                                        @php
                                            $un = $v['undertime_notice'];
                                            $unIssuer = trim(($un->issuer->first_name ?? '').' '.($un->issuer->last_name ?? '')) ?: ($un->issuer->name ?? 'Unknown');
                                            $unSanction = \App\Models\HabitualViolationNotice::OFFENSE_SANCTIONS[$un->offense_number] ?? $un->offense_number;
                                        @endphp
                                        <span class="tlm-notice-badge" title="{{ \App\Models\HabitualViolationNotice::LEGAL_BASIS[\App\Models\HabitualViolationNotice::VIOLATION_UNDERTIME] }}">
                                            <span><i class="fas fa-check-circle"></i> Undertime: {{ $ordinals[$un->offense_number] ?? $un->offense_number }} Offense - {{ $unSanction }}</span>
                                            <small>issued by {{ $unIssuer }} on {{ $un->created_at->format('M j, Y') }}</small>
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('attendance.time-logs-monitoring.issue-notice') }}"
                                              class="tlm-notice-form" data-violation-label="Frequent Undertime"
                                              data-employee="{{ $empName }}"
                                              data-legal-basis="{{ \App\Models\HabitualViolationNotice::LEGAL_BASIS[\App\Models\HabitualViolationNotice::VIOLATION_UNDERTIME] }}">
                                            @csrf
                                            <input type="hidden" name="employee_id" value="{{ $v['employee']->id }}">
                                            <input type="hidden" name="violation_type" value="{{ \App\Models\HabitualViolationNotice::VIOLATION_UNDERTIME }}">
                                            <input type="hidden" name="year" value="{{ $year }}">
                                            <button type="submit" class="hris-btn hris-btn-warning hris-btn-sm">
                                                <i class="fas fa-file-circle-exclamation"></i> Issue Notice (Undertime)
                                            </button>
                                        </form>
                                    @endif
                                @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="hris-empty-state">
                                <div class="hris-empty-state-icon"><i class="fas fa-circle-check"></i></div>
                                <div class="hris-empty-state-title">No Habitual Violations</div>
                                <p class="hris-empty-state-text">
                                    @if ($violationSearch !== '')
                                        No employees match your search.
                                    @else
                                        No employees crossed the habitual threshold in {{ $year }}.
                                    @endif
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:.75rem 1.25rem;">
        {{ $violations->links() }}
    </div>
</div>

{{-- Breakdown modal, shared by the Tardiness and Undertime cells --}}
<div class="modal-overlay" id="breakdown-modal">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="closeBreakdownModal()">&times;</button>
        <h3 id="breakdown-modal-title"></h3>
        <div id="breakdown-modal-body"></div>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
document.querySelectorAll('.breakdown-cell').forEach(function (cell) {
    cell.addEventListener('click', function () {
        var deptName = this.dataset.dept;
        var label = this.dataset.label;
        var unit = this.dataset.unit;
        var employees = JSON.parse(this.dataset.employees || '[]');

        document.getElementById('breakdown-modal-title').textContent = label + ' - ' + deptName;

        var body = document.getElementById('breakdown-modal-body');
        body.innerHTML = employees.length
            ? employees.map(function (e) {
                return '<div class="detail-row"><span>' + e.name + '</span><span>' + e.days + ' ' + unit + '</span></div>';
            }).join('')
            : '<p style="color:#94a3b8;">No employees this month.</p>';

        document.getElementById('breakdown-modal').classList.add('active');
    });
});

function closeBreakdownModal() {
    document.getElementById('breakdown-modal').classList.remove('active');
}

document.getElementById('breakdown-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeBreakdownModal();
    }
});

document.querySelectorAll('.tlm-clickable-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
        var employee = this.dataset.employee;
        var monthLabel = this.dataset.monthLabel;
        var violationType = this.dataset.violationType;
        var dates = JSON.parse(this.dataset.dates || '[]');

        document.getElementById('breakdown-modal-title').textContent =
            violationType + ' Dates - ' + employee + ' (' + monthLabel + ')';

        var body = document.getElementById('breakdown-modal-body');
        body.innerHTML = dates.length
            ? dates.map(function (d) {
                return '<div class="detail-row"><span>' + d.date + '</span><span>' + d.minutes + ' min ' + violationType.toLowerCase() + '</span></div>';
            }).join('')
            : '<p style="color:#94a3b8;">No dates recorded.</p>';

        document.getElementById('breakdown-modal').classList.add('active');
    });
});

document.querySelectorAll('.tlm-notice-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var label = form.dataset.violationLabel;
        var name = form.dataset.employee;
        var basis = form.dataset.legalBasis;
        Swal.fire({
            icon: 'warning',
            title: 'Issue ' + label + ' Notice?',
            html: 'This records the next sequential offense (1st &rarr; 2nd &rarr; 3rd, then wraps back to 1st) for <b>' + name + '</b> and notifies them by email. This cannot be undone.'
                + '<p style="margin-top:.75rem;font-size:.78rem;color:#64748b;">' + basis + '</p>',
            showCancelButton: true,
            confirmButtonText: 'Yes, issue notice',
            confirmButtonColor: '#b45309',
            cancelButtonColor: '#6b7280',
        }).then(function (res) {
            if (res.isConfirmed) {
                form.submit();
            }
        });
    });
});

@if (session('success'))
    Swal.fire({ icon: 'success', title: 'Done', text: @json(session('success')), confirmButtonColor: '#3b82f6' });
@endif
@if (session('error'))
    Swal.fire({ icon: 'error', title: 'Error', text: @json(session('error')) });
@endif
</script>
@endsection
