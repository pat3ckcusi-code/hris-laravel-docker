@extends('dashboards.layout', [
    'title' => 'Leave Management',
    'subtitle' => 'Pending leave queue with approval workflow and leave usage analytics.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="leave" data-url="{{ $leaveDataUrl }}" data-action-url="{{ $leaveActionBaseUrl }}" data-csrf="{{ csrf_token() }}" data-pagination='@json($leavePagination)'>
        <div class="hrm-toolbar">
            <select id="leaveDepartment">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}" @selected($leaveFilters['department'] == (string) $department->Dept_id)>{{ $department->Dept_name }}</option>
                @endforeach
            </select>
            <select id="leaveStatus">
                <option value="pending" @selected($leaveFilters['status'] === 'pending')>Pending</option>
                <option value="approved" @selected($leaveFilters['status'] === 'approved')>Approved</option>
                <option value="declined" @selected($leaveFilters['status'] === 'declined')>Rejected</option>
                <option value="all" @selected($leaveFilters['status'] === 'all')>All</option>
            </select>
            <button class="hrm-btn" id="leaveFilterBtn" type="button">Apply Filter</button>
        </div>

        <div class="hrm-chart-card">
            <h4>Leave Balances and Usage</h4>
            <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="leaveUsageChart"></canvas></div>
        </div>

        <div class="hrm-table-wrap">
            <table class="hrm-table" id="leaveTable" data-initial-chart='@json($leaveChart)'>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Leave Type</th>
                        <th>Period</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requests as $requestItem)
                        <tr data-id="{{ $requestItem['id'] }}">
                            <td>{{ $requestItem['employee_name'] }}</td>
                            <td>{{ $requestItem['department'] }}</td>
                            <td>{{ $requestItem['leave_type'] }}</td>
                            <td>{{ $requestItem['period'] }}</td>
                            <td>{{ $requestItem['days'] }}</td>
                            <td><span class="status-chip status-{{ $requestItem['status'] }}">{{ strtoupper($requestItem['status']) }}</span></td>
                            <td>
                                <button class="hrm-btn-secondary hrm-leave-approve" type="button">Approve</button>
                                <button class="hrm-btn-secondary hrm-leave-reject" type="button">Reject</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div id="leavePagination" class="hrm-pagination"></div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
