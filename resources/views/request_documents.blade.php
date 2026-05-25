@extends('dashboards.layout', [
    'title' => 'Request Documents',
    'subtitle' => 'Submit official document requests and track processing status.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/request_documents.css', 'resources/js/request_documents.js'])
    @include('partials.table-styles')
@endsection

@section('content')
    <div class="request-documents-layout">
        <section class="tile request-form-tile">
            <h2 style="margin-top: 0;">New Document Request</h2>

            <form id="documentRequestForm" method="POST" action="{{ url('/document-requests') }}" class="pds-form">
                @csrf
                <input type="hidden" name="EmpNo" value="{{ $user->EmpNo }}">

                <div class="pds-section">
                    <div class="field-grid">
                        <label>
                            Document Type
                            <select id="document_type" name="document_type" class="form-input" required>
                                <option value="">Select document type</option>
                                @forelse ($documentTypes as $docType)
                                    <option value="{{ $docType->name }}">{{ $docType->name }}</option>
                                @empty
                                    <option value="" disabled>No document types available — contact Front Desk</option>
                                @endforelse
                            </select>
                        </label>

                        <label>
                            Purpose
                            <textarea
                                id="purpose"
                                name="purpose"
                                class="form-input request-purpose"
                                rows="5"
                                maxlength="1000"
                                placeholder="State the reason and details of your request"
                                required
                            >{{ old('purpose') }}</textarea>
                            <div class="purpose-counter">
                                <span id="purposeCount">0</span>/1000 characters
                            </div>
                        </label>
                    </div>
                </div>

                <div class="actions" style="margin-top: 12px;">
                    <button type="submit" class="btn" id="submitRequestBtn">Submit Request</button>
                    <button type="reset" class="btn btn-sm" id="resetRequestBtn">Reset</button>
                </div>
            </form>
        </section>

        <section class="tile request-table-tile">
            <div class="request-table-heading">
                <h2 style="margin: 0;">Request History</h2>
            </div>

            <div style="overflow:auto">
                <table id="documentRequestsTable" class="display leave-table" style="width:100%">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Document</th>
                            <th>Status</th>
                            <th>Purpose</th>
                            <th>HR Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $requestItem)
                            <tr>
                                <td>{{ optional($requestItem->requested_on)->format('M d, Y h:i A') ?? '-' }}</td>
                                <td>{{ $requestItem->document_type }}</td>
                                <td>
                                    @php
                                        $status = strtolower((string) $requestItem->status);
                                        $badgeClass = match ($status) {
                                            'requested' => 'badge-requested',
                                            'pending' => 'badge-pending',
                                            'completed' => 'badge-completed',
                                            'rejected' => 'badge-rejected',
                                            default => 'badge-default',
                                        };
                                    @endphp
                                    <span class="badge {{ $badgeClass }}">{{ $requestItem->status }}</span>
                                </td>
                                <td class="purpose-cell">{{ $requestItem->purpose }}</td>
                                <td class="notes-cell">{{ $requestItem->hr_notes ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px">{{ $requests->links() }}</div>
        </section>
    </div>
@endsection
