@extends('dashboards.layout', [
    'title' => 'Front Desk',
    'subtitle' => 'Document request workflow from Requested to Completed.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="frontdesk" data-url="{{ $frontdeskDataUrl }}" data-action-url="{{ $frontdeskActionBaseUrl }}" data-complete-url="{{ $frontdeskCompleteBaseUrl }}" data-csrf="{{ csrf_token() }}" data-pagination='@json($frontdeskPagination)'>
        <div class="hrm-toolbar">
            <select id="frontdeskDepartment">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}">{{ $department->Dept_name }}</option>
                @endforeach
            </select>
            <select id="frontdeskStatus">
                <option value="all">All Status</option>
                <option value="requested">Requested</option>
                <option value="accepted">Accepted</option>
                <option value="approved">Approved</option>
                <option value="completed">Completed</option>
                <option value="rejected">Rejected</option>
            </select>
            <button class="hrm-btn" id="frontdeskFilterBtn" type="button">Apply Filter</button>
        </div>

        <div class="hrm-table-wrap">
            <table class="hrm-table hris-table" id="frontdeskTable">
                <thead>
                    <tr>
                        <th>EmpNo</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Document</th>
                        <th>Status Workflow</th>
                        <th>Requested On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requests as $requestItem)
                        <tr data-id="{{ $requestItem['id'] }}" data-empno="{{ $requestItem['emp_no'] }}" data-name="{{ $requestItem['employee_name'] }}" data-doc="{{ $requestItem['document_type'] }}">
                            <td>{{ $requestItem['emp_no'] }}</td>
                            <td>{{ $requestItem['employee_name'] }}</td>
                            <td>{{ $requestItem['department'] }}</td>
                            <td>{{ $requestItem['document_type'] }}</td>
                            <td><span class="status-chip status-{{ $requestItem['status'] }}">{{ strtoupper($requestItem['status']) }}</span></td>
                            <td>{{ $requestItem['requested_on'] }}</td>
                            <td>
                                <button class="hrm-btn-secondary hrm-frontdesk-accept" type="button">Accept</button>
                                <button class="hrm-btn-secondary hrm-frontdesk-reject" type="button">Reject</button>
                                <button class="hrm-btn-secondary hrm-frontdesk-approve" type="button">Approve</button>
                                <button class="hrm-btn-secondary hrm-frontdesk-complete" type="button">Completed</button>
                                <button class="hrm-btn-secondary hrm-frontdesk-print" type="button">Print Certificate</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div id="frontdeskPagination" class="hrm-pagination"></div>

        <template id="certificateTemplate">
            <div class="print-certificate">
                <img src="{{ $headerImage }}" alt="Header" class="print-header">
                <h2>Certificate Release Slip</h2>
                <p><strong>Employee:</strong> <span data-print="name"></span></p>
                <p><strong>Employee No:</strong> <span data-print="empno"></span></p>
                <p><strong>Document:</strong> <span data-print="document"></span></p>
                <p><strong>Date Issued:</strong> <span data-print="date"></span></p>
                <img src="{{ $footerImage }}" alt="Footer" class="print-footer">
            </div>
        </template>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
