@extends('dashboards.layout', [
    'title' => 'Request Documents',
    'subtitle' => 'Submit official document requests and track processing status.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/request_documents.css', 'resources/js/request_documents.js', 'resources/css/hris-table.css', 'resources/js/hris-table.js'])
    @include('partials.table-styles')
@endsection

@section('content')
    <div class="request-documents-layout">
        @if (session('success'))
            <div class="alert alert-success" role="alert" style="margin-bottom: 12px;">
                {{ session('success') }}
            </div>
        @endif

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
                                    <option value="" disabled>No document types available - contact Front Desk</option>
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
            <x-hris.table-layout
                title="Request History"
                subtitle="Track your document requests and statuses."
                :paginator="$requests"
                :showExport="false"
                :monthFilterDefault="now()->month"
            >
                @php
                    $currentSort = request('sort');
                    $currentDir = strtolower(request('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
                    $sortUrl = function ($column) use ($currentSort, $currentDir) {
                        $params = request()->except('page');
                        $params['sort'] = $column;
                        $params['dir'] = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
                        return request()->url() . '?' . http_build_query($params);
                    };
                    $activeClass = function ($column) use ($currentSort) {
                        return $currentSort === $column ? 'text-blue-600 font-semibold' : 'text-slate-600';
                    };
                @endphp

                <div class="hris-table-wrapper">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th><a href="{{ $sortUrl('requested_on') }}" class="{{ $activeClass('requested_on') }}">Date</a></th>
                                <th><a href="{{ $sortUrl('document_type') }}" class="{{ $activeClass('document_type') }}">Document</a></th>
                                <th><a href="{{ $sortUrl('status') }}" class="{{ $activeClass('status') }}">Status</a></th>
                                <th>Purpose</th>
                                <th>HR Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $requestItem)
                                <tr>
                                    <td>{{ optional($requestItem->requested_on)->format('M d, Y h:i A') ?? '-' }}</td>
                                    <td>{{ $requestItem->document_type }}</td>
                                    <td><x-hris.status-badge :status="$requestItem->status" /></td>
                                    <td>{{ $requestItem->purpose }}</td>
                                    <td>{{ $requestItem->hr_notes ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-hris.table-layout>
        </section>
    </div>
@endsection
