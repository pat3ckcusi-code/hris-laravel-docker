@extends('dashboards.layout')

@php
    $title = 'Request Documents';
    $subtitle = 'Submit requests for official employment-related documents.';
@endphp

@section('content')
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h2 class="text-2xl font-semibold text-slate-900 mb-2">Request Documents</h2>
            <p class="text-slate-600 text-sm mb-6">Submit requests for official employment-related documents to obtain certificates, employment letters, or other HR-issued documents without manual follow-ups.</p>

            <form method="POST" action="{{ route('document-requests.store') }}" class="space-y-6">
                @csrf
                
                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Document Type *</span>
                            <select name="document_type" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                <option value="">Select document type</option>
                                <option value="certificate_of_employment">Certificate of Employment</option>
                                <option value="certificate_of_creditable_service">Certificate of Creditable Service</option>
                                <option value="employment_letter">Employment Letter</option>
                                <option value="salary_certificate">Salary Certificate</option>
                                <option value="leave_certificate">Leave Certificate</option>
                                <option value="clearance_certificate">Clearance Certificate</option>
                                <option value="other">Other</option>
                            </select>
                            @error('document_type') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Quantity *</span>
                            <input type="number" name="quantity" min="1" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            @error('quantity') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-700">Purpose / Notes</span>
                        <textarea name="purpose" rows="3" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Specify the purpose or any special notes for your request"></textarea>
                        @error('purpose') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-700">Preferred Delivery Method</span>
                        <select name="delivery_method" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select delivery method</option>
                            <option value="pickup">Pickup from HR Office</option>
                            <option value="email">Email</option>
                            <option value="courier">Courier Service</option>
                        </select>
                        @error('delivery_method') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                    </label>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t">
                    <button type="reset" class="inline-flex items-center justify-center rounded-md bg-gray-100 px-6 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2">Clear</button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-6 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">Submit Request</button>
                </div>
            </form>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-md">
            <h2 class="text-xl font-bold text-slate-900 mb-4">Document Request History</h2>
            <p class="text-slate-600 text-sm">Your submitted document requests will appear here.</p>
        </div>
    </div>
@endsection
