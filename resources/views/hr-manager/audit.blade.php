@extends('dashboards.layout', [
    'title' => 'Audit Logs',
    'subtitle' => 'Action logs for Records Manager, Leave Manager, and Front Desk.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="audit" data-url="{{ $auditDataUrl }}" data-pagination='@json($auditPagination)'>
        <div class="hrm-toolbar">
            <select id="auditUser">
                <option value="">All Users</option>
                @foreach($auditUsers as $auditUser)
                    <option value="{{ $auditUser }}">{{ $auditUser }}</option>
                @endforeach
            </select>
            <input type="date" id="auditDate">
            <select id="auditAction">
                <option value="">All Actions</option>
                <option value="edit">Edit</option>
                <option value="update">Update</option>
                <option value="attendance_import">Attendance Import</option>
                <option value="compliance-report">Compliance Report</option>
                <option value="approve">Approve</option>
                <option value="reject">Reject</option>
                <option value="accept">Accept</option>
                <option value="complete">Complete</option>
            </select>
            <button class="hrm-btn" id="auditFilterBtn" type="button">Apply Filter</button>
        </div>

        <div class="hrm-table-wrap">
            <table class="hrm-table hris-table" id="auditTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td>{{ $log['user'] }}</td>
                            <td>{{ $log['role'] }}</td>
                            <td>{{ $log['action'] }}</td>
                            <td>{{ $log['timestamp'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div id="auditPagination" class="hrm-pagination"></div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
