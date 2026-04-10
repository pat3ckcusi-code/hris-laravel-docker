@extends('dashboards.layout', [
    'title' => 'Document Request Control Center',
    'subtitle' => 'Review, filter, print, and update document request workflows from one front desk console.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/front_desk.css', 'resources/js/front_desk.js'])
@endsection

@section('content')
    <div
        id="frontDeskApp"
        class="request-control-page"
        data-fetch-url="{{ url('/dashboard/employee/front-desk/requests') }}"
        data-accept-url="{{ url('/dashboard/employee/front-desk/accept') }}"
        data-reject-url="{{ url('/dashboard/employee/front-desk/reject') }}"
        data-complete-url="{{ url('/dashboard/employee/front-desk/complete') }}"
        data-print-base-url="{{ url('/dashboard/employee/front-desk/print') }}"
        data-print-report-url="{{ url('/dashboard/employee/front-desk/print-report') }}"
    >
        <section class="summary-grid" aria-label="Request summary">
            <article class="summary-card summary-total">
                <span class="summary-label">Total Requests</span>
                <strong id="summaryTotal">{{ $summary['total'] }}</strong>
            </article>
            <article class="summary-card summary-pending">
                <span class="summary-label">Pending Requests</span>
                <strong id="summaryPending">{{ $summary['pending'] }}</strong>
            </article>
            <article class="summary-card summary-approved">
                <span class="summary-label">Approved Requests</span>
                <strong id="summaryApproved">{{ $summary['approved'] }}</strong>
            </article>
            <article class="summary-card summary-completed">
                <span class="summary-label">Completed</span>
                <strong id="summaryCompleted">{{ $summary['completed'] }}</strong>
            </article>
        </section>

        <section class="tile filter-tile">
            <div class="filter-heading">
                <h2 style="margin: 0;">Filters & Reports</h2>
                <p class="muted" style="margin: 0;">Filter live queues and print targeted reports.</p>
            </div>

            <div class="filter-grid">
                <label>
                    Date
                    <input type="date" id="filterDate" class="form-input">
                </label>
                <label>
                    Month
                    <input type="month" id="filterMonth" class="form-input">
                </label>
                <label>
                    Document Type
                    <select id="filterDocumentType" class="form-input">
                        <option value="">All document types</option>
                        @foreach ($documentTypes as $documentType)
                            <option value="{{ $documentType }}">{{ $documentType }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Status
                    <select id="filterStatus" class="form-input">
                        <option value="">All statuses</option>
                        <option value="Requested">Requested</option>
                        <option value="Accepted">Accepted</option>
                        <option value="Completed">Completed</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </label>
            </div>

            <div class="filter-actions">
                <button type="button" class="btn" id="applyFiltersBtn">Apply Filters</button>
                <button type="button" class="btn secondary-filter-btn" id="resetFiltersBtn">Reset</button>
                <button type="button" class="btn pending-print-btn" data-print-scope="pending">Print Pending</button>
                <button type="button" class="btn approved-print-btn" data-print-scope="approved">Print Approved</button>
                <button type="button" class="btn filtered-print-btn" data-print-scope="all">Print Filtered</button>
            </div>
        </section>

        <section class="request-tables-grid">
            <section class="tile table-tile">
                <div class="table-header-row">
                    <h2 style="margin: 0;">Pending Requests</h2>
                </div>
                <div class="table-wrap">
                    <table id="pendingRequestsTable" class="display request-control-table" style="width:100%">
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
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <section class="tile table-tile">
                <div class="table-header-row">
                    <h2 style="margin: 0;">Approved Requests</h2>
                </div>
                <div class="table-wrap">
                    <table id="approvedRequestsTable" class="display request-control-table" style="width:100%">
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
                        <tbody></tbody>
                    </table>
                </div>
            </section>
        </section>
    </div>
@endsection
