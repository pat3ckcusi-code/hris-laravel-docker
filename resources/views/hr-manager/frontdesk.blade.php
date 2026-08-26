@extends('dashboards.layout', [
    'title' => 'Document Signing',
    'subtitle' => 'Review and apply your e-signature to documents forwarded by Front Desk.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <div class="ds-stats">
        <div class="ds-stat-card ds-stat-pending">
            <div class="ds-stat-icon"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <p>Awaiting My Signature</p>
                <h3>{{ $awaitingCount }}</h3>
            </div>
        </div>
        <div class="ds-stat-card ds-stat-signed">
            <div class="ds-stat-icon"><i class="fas fa-signature"></i></div>
            <div>
                <p>Signed</p>
                <h3>{{ $signedCount }}</h3>
            </div>
        </div>
        <div class="ds-stat-card ds-stat-rejected">
            <div class="ds-stat-icon"><i class="fas fa-rotate-left"></i></div>
            <div>
                <p>Rejected by Me</p>
                <h3>{{ $rejectedCount }}</h3>
            </div>
        </div>
    </div>

    <section class="hrm-module" data-module="frontdesk" data-url="{{ $frontdeskDataUrl }}" data-action-url="{{ $frontdeskActionBaseUrl }}" data-preview-url="{{ $frontdeskPreviewBaseUrl }}" data-csrf="{{ csrf_token() }}" data-pagination='@json($frontdeskPagination)'>
        <div class="hrm-toolbar">
            <select id="frontdeskDepartment">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}">{{ $department->Dept_name }}</option>
                @endforeach
            </select>
            <select id="frontdeskStatus">
                <option value="all">All Status</option>
                <option value="awaiting_signature">Awaiting My Signature</option>
                <option value="signed">Signed</option>
                <option value="rejected">Rejected by Me</option>
            </select>
            <button class="hrm-btn" id="frontdeskFilterBtn" type="button"><i class="fas fa-filter"></i> Apply Filter</button>
        </div>

        <div class="hrm-table-wrap">
            <table class="hrm-table hris-table" id="frontdeskTable">
                <thead>
                    <tr>
                        <th>EmpNo</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Document</th>
                        <th>Status</th>
                        <th>Requested On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $requestItem)
                        <tr data-id="{{ $requestItem['id'] }}" data-empno="{{ $requestItem['emp_no'] }}" data-name="{{ $requestItem['employee_name'] }}" data-doc="{{ $requestItem['document_type'] }}" data-status="{{ $requestItem['status'] }}">
                            <td>{{ $requestItem['emp_no'] }}</td>
                            <td>{{ $requestItem['employee_name'] }}</td>
                            <td>{{ $requestItem['department'] }}</td>
                            <td>{{ $requestItem['document_type'] }}</td>
                            <td><span class="status-chip status-{{ $requestItem['status'] }}">{{ strtoupper(str_replace('_', ' ', $requestItem['status'])) }}</span></td>
                            <td>{{ $requestItem['requested_on'] }}</td>
                            <td>
                                <button class="hrm-btn-secondary hrm-frontdesk-preview" type="button"><i class="fas fa-eye"></i> Preview</button>
                                @if ($requestItem['status'] === 'awaiting_signature')
                                    <button class="hrm-btn-secondary hrm-frontdesk-sign" type="button"><i class="fas fa-signature"></i> Sign</button>
                                    <button class="hrm-btn-secondary hrm-frontdesk-reject" type="button"><i class="fas fa-xmark"></i> Reject</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="ds-empty">
                                    <i class="fas fa-signature"></i>
                                    Nothing here yet — documents Front Desk forwards for signature will appear in this list.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div id="frontdeskPagination" class="hrm-pagination"></div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
