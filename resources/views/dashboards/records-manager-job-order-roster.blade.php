@extends('dashboards.layout', [
    'title' => 'Job Order Roster',
    'subtitle' => 'Appointment period, rate, and funding details for Job Order employees.',
])

@section('tiles')
    <article class="tile">
        <strong>Appointments Listed</strong>
        {{ $rows->count() }} record(s) matching the current filters.
    </article>
@endsection

@section('top_actions')
    <a href="{{ route('dashboard.records-manager.employees') }}" class="btn">Back to Employee Management</a>
    <a href="{{ route('dashboard.records-manager.job-order-roster.export', $filters) }}" class="btn">Download Excel</a>
@endsection

@section('page_head')
    @vite('resources/css/records_manager.css')
    @include('partials.table-styles')
@endsection

@section('content')
    <section aria-label="Job Order roster filters">
        <form method="GET" action="{{ route('dashboard.records-manager.job-order-roster') }}" class="table-toolbar" aria-label="Job Order roster filters">
            <div class="toolbar-inputs">
                <label>
                    Period From
                    <input type="date" name="period_from" value="{{ $filters['period_from'] ?? '' }}">
                </label>

                <label>
                    Period To
                    <input type="date" name="period_to" value="{{ $filters['period_to'] ?? '' }}">
                </label>

                <label>
                    Department
                    <select name="department_id[]" multiple size="5">
                        @foreach ($departments as $department)
                            <option value="{{ $department->Dept_id }}" @selected(in_array((string) $department->Dept_id, array_map('strval', (array) ($filters['department_id'] ?? [])), true))>
                                {{ $department->Dept_name }}
                            </option>
                        @endforeach
                    </select>
                    <span class="settings-hint">Hold Ctrl (Cmd on Mac) to select multiple. None selected = all departments.</span>
                </label>
            </div>

            <div class="toolbar-actions">
                <button type="submit" class="record-btn toolbar-btn">Apply</button>
                <a href="{{ route('dashboard.records-manager.job-order-roster') }}" class="toolbar-reset">Reset</a>
            </div>
        </form>

        <p class="create-note">
            @if (($filters['period_from'] ?? null) || ($filters['period_to'] ?? null))
                Showing appointments overlapping the selected period range.
            @else
                Showing currently active appointments as of {{ now()->format('F j, Y') }}. Set a period range above to view a historical roster.
            @endif
        </p>

        <div class="table-wrap">
            <table class="employee-table hris-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Name of Appointee/s</th>
                        <th>Designation</th>
                        <th>Rate Per Day</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Funding Charging</th>
                        <th>Office</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $appointment)
                        <tr>
                            <td>{{ $appointment->employee?->name }}</td>
                            <td>{{ $appointment->designation ?: '-' }}</td>
                            <td>{{ $appointment->rateLabel() }}</td>
                            <td>{{ $appointment->period_from->format('F j, Y') }}</td>
                            <td>{{ $appointment->period_until->format('F j, Y') }}</td>
                            <td>{{ $appointment->funding_source ?: '-' }}</td>
                            <td>{{ $appointment->office ?: '-' }}</td>
                            <td>{{ $appointment->remarks ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">No Job Order appointments match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
