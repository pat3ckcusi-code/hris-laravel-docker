@extends('dashboards.layout', [
    'title' => $isAdmin ? 'Daily Time Records' : 'My Daily Time Records',
    'subtitle' => $isAdmin
        ? 'View biometric attendance records for all employees.'
        : 'View your biometric attendance records.',
])

@section('content')
    @if ($isAdmin)
        {{-- ── Admin filter bar ── --}}
        <form method="GET" action="{{ route('attendance.dtr') }}" style="margin-bottom:1.5rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;">
            <div>
                <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">Department</label>
                <select name="dept_id" class="hrm-input" style="min-width:160px;">
                    <option value="">All Departments</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->Dept_id }}" @selected(request('dept_id') == $dept->Dept_id)>
                            {{ $dept->Dept_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">Employee</label>
                <select name="employee_id" class="hrm-input" style="min-width:180px;">
                    <option value="">All Employees</option>
                    @foreach ($employees as $emp)
                        <option value="{{ $emp->id }}" @selected(request('employee_id') == $emp->id)>
                            {{ trim(($emp->last_name ?? $emp->lastname ?? '') . ', ' . ($emp->first_name ?? $emp->firstname ?? '')) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">From</label>
                <input type="date" name="from_date" class="hrm-input" value="{{ request('from_date') }}">
            </div>
            <div>
                <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">To</label>
                <input type="date" name="to_date" class="hrm-input" value="{{ request('to_date') }}">
            </div>
            <div>
                <button type="submit" class="hrm-btn hrm-btn-primary">Filter</button>
                <a href="{{ route('attendance.dtr') }}" class="hrm-btn" style="margin-left:.5rem;">Reset</a>
            </div>
        </form>
    @endif

    {{-- ── Download Form 48 ── --}}
    <details style="margin-bottom:1.5rem;background:#f9fafb;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;">
        <summary style="cursor:pointer;font-weight:600;">Download Form 48 (CSC Form 48 DTR)</summary>
        <form method="GET" action="{{ route('attendance.dtr.download') }}" style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;">
            @if ($isAdmin)
                <div>
                    <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">Employee</label>
                    <select name="employee_id" class="hrm-input" style="min-width:200px;" required>
                        <option value="">Select Employee</option>
                        @foreach ($employees as $emp)
                            <option value="{{ $emp->id }}">
                                {{ trim(($emp->last_name ?? $emp->lastname ?? '') . ', ' . ($emp->first_name ?? $emp->firstname ?? '')) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">From</label>
                <input type="date" name="from_date" class="hrm-input" required>
            </div>
            <div>
                <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">To</label>
                <input type="date" name="to_date" class="hrm-input" required>
            </div>
            <div>
                <label style="display:block;font-size:.8rem;margin-bottom:.2rem;">Month / Year label</label>
                <input type="text" name="month_year" class="hrm-input" placeholder="e.g. June 2026" style="min-width:130px;">
            </div>
            <div>
                <button type="submit" class="hrm-btn hrm-btn-primary">Download Excel</button>
            </div>
        </form>
    </details>

    {{-- ── Records table ── --}}
    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$records">
        <table class="hris-table">
            <thead>
                <tr>
                    @if ($isAdmin)
                        <th>Employee</th>
                    @endif
                    <th>Date</th>
                    <th class="text-center">AM In</th>
                    <th class="text-center">AM Out</th>
                    <th class="text-center">PM In</th>
                    <th class="text-center">PM Out</th>
                    <th class="text-center">Late (min)</th>
                    <th class="text-center">Undertime (min)</th>
                    <th class="text-center">Source</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        @if ($isAdmin)
                            <td>
                                {{ trim((optional($record->employee)->last_name ?? optional($record->employee)->lastname ?? '') . ', ' . (optional($record->employee)->first_name ?? optional($record->employee)->firstname ?? '')) ?: '—' }}
                            </td>
                        @endif
                        <td>{{ \Carbon\Carbon::parse($record->date)->format('M d, Y (D)') }}</td>
                        <td class="text-center">{{ $record->time_in_am ?? '—' }}</td>
                        <td class="text-center">{{ $record->time_out_am ?? '—' }}</td>
                        <td class="text-center">{{ $record->time_in_pm ?? '—' }}</td>
                        <td class="text-center">{{ $record->time_out_pm ?? '—' }}</td>
                        <td class="text-center">{{ $record->late_minutes ?? 0 }}</td>
                        <td class="text-center">{{ $record->undertime_minutes ?? 0 }}</td>
                        <td class="text-center">
                            @if ($record->source === 'biometric')
                                <span class="hris-badge badge-approved">Biometric</span>
                            @elseif ($record->source === 'manual')
                                <span class="hris-badge" style="background:#e5e7eb;color:#374151;">Manual</span>
                            @else
                                <span style="color:#9ca3af;">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($record->is_absent)
                                <span class="hris-badge badge-rejected">Absent</span>
                            @else
                                <span class="hris-badge badge-approved">Present</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isAdmin ? 10 : 9 }}" class="text-center text-muted">No attendance records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection
