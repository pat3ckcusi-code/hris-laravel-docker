@extends('dashboards.layout', [
    'title' => 'Approved Requests',
    'subtitle' => 'View completed and accepted document requests.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/front_desk.css', 'resources/js/front_desk.js'])
@endsection

@section('content')
    <div class="request-control-page">
        <section class="summary-grid" aria-label="Request summary">
            <article class="summary-card summary-total">
                <span class="summary-label">Total Approved</span>
                <strong id="summaryTotal">{{ $summary['approved'] + $summary['completed'] ?? 0 }}</strong>
            </article>
            <article class="summary-card summary-approved">
                <span class="summary-label">Accepted</span>
                <strong id="summaryApproved">{{ $summary['approved'] }}</strong>
            </article>
        </section>

        <section class="tile table-tile">
            <div class="table-header-row">
                <h2 style="margin: 0;">Approved Requests</h2>
            </div>
            <div class="table-wrap">
                <table class="display request-control-table" style="width:100%">
                    <thead>
                        <tr>
                            <th>Emp No.</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Document Type</th>
                            <th>Purpose</th>
                            <th>Requested On</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requests as $request)
                            <tr>
                                <td>{{ $request['emp_no'] }}</td>
                                <td>{{ $request['employee_name'] }}</td>
                                <td>{{ $request['department'] }}</td>
                                <td>{{ $request['document_type'] }}</td>
                                <td>{{ $request['purpose'] }}</td>
                                <td>{{ $request['requested_on'] }}</td>
                                <td><span class="badge badge-success">{{ $request['status'] }}</span></td>
                                <td>{{ $request['remarks'] }}</td>
                                <td>
                                    <button class="btn btn-sm" onclick="completeRequest({{ $request['id'] }})">Complete</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 20px;">No approved requests.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
