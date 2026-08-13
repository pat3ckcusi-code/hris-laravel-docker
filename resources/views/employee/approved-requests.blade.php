@extends('dashboards.layout', [
    'title' => 'Approved Requests',
    'subtitle' => 'Manage accepted and completed document requests.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/front_desk.css', 'resources/js/front_desk.js'])
@endsection

@section('content')
    <div class="request-control-page">
        <section class="summary-grid" aria-label="Request summary">
            <article class="summary-card summary-approved">
                <span class="summary-icon"><i class="fas fa-check-circle"></i></span>
                <div>
                    <span class="summary-label">Approved Requests</span>
                    <strong id="summaryTotal">{{ $summary['approved'] ?? 0 }}</strong>
                </div>
            </article>
            <article class="summary-card summary-total">
                <span class="summary-icon"><i class="fas fa-file-lines"></i></span>
                <div>
                    <span class="summary-label">Document Types</span>
                    <strong id="summaryApproved">{{ $documentTypes->count() }}</strong>
                </div>
            </article>
        </section>

        <section class="tile table-tile">
            <div class="table-header-row">
                <h2><i class="fas fa-check-circle"></i> Approved Requests</h2>
            </div>
            <div class="table-wrap">
                <table class="display request-control-table hris-table" style="width:100%">
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
                                <td class="cell-truncate" title="{{ $request['purpose'] }}">{{ $request['purpose'] }}</td>
                                <td>{{ $request['requested_on'] }}</td>
                                <td><span class="request-badge badge-approved">{{ $request['status'] }}</span></td>
                                <td class="cell-truncate" title="{{ $request['remarks'] }}">{{ $request['remarks'] }}</td>
                                <td>
                                    <div class="fd-actions">
                                        <a href="{{ url('/dashboard/employee/front-desk/print/' . $request['id']) }}"
                                           class="fd-action-btn fd-print-btn"
                                           target="_blank"
                                           rel="noopener noreferrer">
                                            <i class="fas fa-print"></i> Print
                                        </a>
                                        @if ($request['status'] !== 'Completed')
                                            <button type="button"
                                                    class="fd-action-btn fd-complete-btn"
                                                    onclick="completeRequest({{ $request['id'] }})">
                                                <i class="fas fa-box-open"></i> Complete
                                            </button>
                                        @endif
                                    </div>
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

    <script>
        function completeRequest(id) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const hasSwal = window.Swal && typeof window.Swal.fire === 'function';

            const confirmAndSubmit = function () {
                fetch('/requests/' + id + '/complete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: '_token=' + encodeURIComponent(csrfToken),
                })
                    .then(function (response) { return response.json(); })
                    .then(function (response) {
                        if (!response.success) {
                            throw new Error('Failed to complete request.');
                        }

                        if (hasSwal) {
                            window.Swal.fire('Completed!', 'Request marked as complete.', 'success')
                                .then(function () { window.location.reload(); });
                            return;
                        }

                        window.alert('Request marked as complete.');
                        window.location.reload();
                    })
                    .catch(function () {
                        if (hasSwal) {
                            window.Swal.fire('Error', 'Unable to complete the request.', 'error');
                            return;
                        }

                        window.alert('Unable to complete the request.');
                    });
            };

            if (hasSwal) {
                window.Swal.fire({
                    title: 'Mark as Complete',
                    text: 'Are you sure you want to complete this request?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Confirm',
                    cancelButtonText: 'Cancel',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        confirmAndSubmit();
                    }
                });

                return;
            }

            if (window.confirm('Are you sure you want to complete this request?')) {
                confirmAndSubmit();
            }
        }
    </script>
@endsection
