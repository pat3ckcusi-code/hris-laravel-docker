@extends('dashboards.layout', [
    'title' => 'Pending Requests',
    'subtitle' => 'Review and process pending document requests.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/front_desk.css', 'resources/js/front_desk.js'])
@endsection

@section('content')
    <div class="request-control-page">
        <section class="summary-grid" aria-label="Request summary">
            <article class="summary-card summary-total">
                <span class="summary-label">Total Pending</span>
                <strong id="summaryTotal">{{ $summary['pending'] ?? 0 }}</strong>
            </article>
            <article class="summary-card summary-pending">
                <span class="summary-label">Pending</span>
                <strong id="summaryPending">{{ $summary['pending'] ?? 0 }}</strong>
            </article>
        </section>

        <section class="tile table-tile">
            <div class="table-header-row">
                <h2 style="margin: 0;">Pending Requests</h2>
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
                                <td>{{ $request['purpose'] }}</td>
                                <td>{{ $request['requested_on'] }}</td>
                                <td><span class="badge badge-info">{{ $request['status'] }}</span></td>
                                <td>{{ $request['remarks'] }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="acceptRequest({{ $request['id'] }})" title="Accept this request"><i class="fas fa-check"></i> Accept</button>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="rejectRequest({{ $request['id'] }})" title="Reject this request"><i class="fas fa-times"></i> Reject</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 20px;">No pending requests.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- Inline script for Accept/Reject actions --}}
    <script>
        // Global functions for server-rendered Pending Requests view
        window.getSwal = function() {
            return window.Swal && typeof window.Swal.fire === 'function' ? window.Swal : null;
        };

        window.getCsrfToken = function() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        };

        window.acceptRequest = async function(requestId) {
            const Swal = window.getSwal();
            const confirmed = await (Swal
                ? Swal.fire({
                    icon: 'question',
                    title: 'Confirm Accept',
                    text: 'Are you sure you want to accept this request?',
                    showCancelButton: true,
                    confirmButtonText: 'Accept',
                    cancelButtonText: 'Cancel',
                })
                : { isConfirmed: window.confirm('Accept this request?') });

            if (!confirmed.isConfirmed) return;

            try {
                const response = await fetch(
                    '/dashboard/employee/front-desk/accept',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': window.getCsrfToken(),
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ request_id: requestId }),
                    }
                );

                if (!response.ok) {
                    throw new Error(`Failed to accept request: HTTP ${response.status}`);
                }

                const data = await response.json();

                if (Swal) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message || 'Request accepted successfully.',
                    });
                } else {
                    alert(data.message || 'Request accepted successfully.');
                }

                // Reload the page to reflect changes
                window.location.reload();
            } catch (error) {
                const message = error instanceof Error ? error.message : 'An error occurred.';
                if (Swal) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: message,
                    });
                } else {
                    alert(message);
                }
            }
        };

        window.rejectRequest = async function(requestId) {
            const Swal = window.getSwal();

            if (!Swal) {
                const reason = window.prompt('Please provide a reason for rejection:');
                if (!reason || reason.trim() === '') {
                    alert('Rejection reason is required.');
                    return;
                }
                window.submitReject(requestId, reason);
                return;
            }

            const result = await Swal.fire({
                icon: 'question',
                title: 'Reject Request',
                text: 'Please provide a reason for rejection:',
                input: 'textarea',
                inputPlaceholder: 'Enter rejection reason...',
                inputValidator: (value) => {
                    if (!value || value.trim() === '') {
                        return 'Rejection reason is required.';
                    }
                },
                showCancelButton: true,
                confirmButtonText: 'Reject',
                cancelButtonText: 'Cancel',
            });

            if (result.isConfirmed) {
                await window.submitReject(requestId, result.value);
            }
        };

        window.submitReject = async function(requestId, reason) {
            const Swal = window.getSwal();

            try {
                const response = await fetch(
                    '/dashboard/employee/front-desk/reject',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': window.getCsrfToken(),
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            request_id: requestId,
                            remarks: reason,
                        }),
                    }
                );

                if (!response.ok) {
                    throw new Error(`Failed to reject request: HTTP ${response.status}`);
                }

                const data = await response.json();

                if (Swal) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message || 'Request rejected successfully.',
                    });
                } else {
                    alert(data.message || 'Request rejected successfully.');
                }

                // Reload the page to reflect changes
                window.location.reload();
            } catch (error) {
                const message = error instanceof Error ? error.message : 'An error occurred.';
                if (Swal) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: message,
                    });
                } else {
                    alert(message);
                }
            }
        };
    </script>
@endsection
