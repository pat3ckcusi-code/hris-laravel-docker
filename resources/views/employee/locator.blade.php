@extends('dashboards.layout')

@php
    $title = 'Locator';
    $subtitle = 'File Locator entries and print locator slips.';
    $locators = $locators ?? collect();
@endphp

@section('page_styles')
    @vite(['resources/css/hris-table.css', 'resources/js/hris-table.js'])
@endsection

@section('modals')
<dialog id="locatorModal" class="employee-modal" style="max-width:800px">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 id="locator-modal-title" style="margin:0">View details for your locator request</h3>
            <div class="record-email" style="font-size:0.9rem;color:#64748b">View details for your locator request</div>
        </div>
        <div id="locator-modal-actions" style="display:flex;gap:8px;align-items:center"></div>
    </div>
    <div id="locator-modal-body" style="margin-top:8px;"></div>
    <form method="dialog" class="modal-actions" style="margin-top:12px; text-align:right">
        <button class="btn" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_head')
    @include('partials.table-styles')
    <style>
        /* Filed Locators table: force fixed layout so long content wraps instead of
           widening the table past its wrapper and triggering horizontal scroll. */
        #filed-locators-table {
            table-layout: fixed;
        }
        /* Only body values wrap - headers are short static labels and should
           stay on one line (forcing them to wrap mid-word looked broken). */
        #filed-locators-table td {
            overflow-wrap: break-word !important;
            word-break: break-word !important;
            white-space: normal !important;
        }
        #filed-locators-table th {
            white-space: nowrap;
        }
        /* .hris-btn sets white-space: nowrap directly on the button, which wins
           over the td-level override above since it targets the element itself. */
        #filed-locators-table .hris-btn-wrap {
            white-space: normal !important;
            text-align: center;
            line-height: 1.2;
        }
        /* .hris-badge is nowrap by design (status words are always short) but has
           no max-width, so in a narrow fixed column it visually overflows into the
           next cell instead of wrapping - shrink it so it reliably fits its column. */
        #filed-locators-table .hris-badge {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            letter-spacing: 0.2px;
        }
        #filed-locators-table td,
        #filed-locators-table th {
            padding: 0.75rem 0.5rem;
        }
    </style>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h2 class="text-2xl font-semibold text-slate-900 mb-4">{{ isset($editLocator) ? 'Update Locator' : 'File Locator' }}</h2>

            @if(session('success'))
                {{-- Success handled via SweetAlert popup below --}}
            @endif

            @if(isset($editLocator))
                <form class="pds-form" method="POST" action="{{ route('employee.locator.update', ['locator' => $editLocator->id]) }}" data-processing-submit>
                    @csrf
                    @method('PUT')
            @else
                <form class="pds-form" method="POST" action="{{ route('employee.locator.store') }}" data-processing-submit>
                    @csrf
            @endif
                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Application Type</span>
                            <select class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" name="application_type" required>
                                <option value="">Select type</option>
                                <option value="Official" {{ old('application_type', $editLocator->application_type ?? '') == 'Official' ? 'selected' : '' }}>Official</option>
                                <option value="Personal" {{ old('application_type', $editLocator->application_type ?? '') == 'Personal' ? 'selected' : '' }}>Personal</option>
                            </select>
                            @error('application_type') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Location</span>
                            <input class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" type="text" name="location" required placeholder="City, Province/State, Country" value="{{ old('location', $editLocator->location ?? '') }}">
                            @error('location') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Date of Travel</span>
                            <input id="travel_date" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" type="date" name="travel_date" required min="{{ date('Y-m-d') }}" value="{{ old('travel_date', $editLocator->travel_date ?? '') }}">
                            @error('travel_date') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Intended Time of Departure</span>
                            <input id="intended_departure_time" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" type="time" name="intended_departure_time" required value="{{ old('intended_departure_time', $editLocator->intended_departure_time ?? '') }}">
                            @error('intended_departure_time') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Intended Time of Arrival</span>
                            <input id="intended_arrival_time" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" type="time" name="intended_arrival_time" required value="{{ old('intended_arrival_time', $editLocator->intended_arrival_time ?? '') }}">
                            @error('intended_arrival_time') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-700">Detail of Travel / Purpose of Travel</span>
                        <textarea class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" name="detail" rows="3" required>{{ old('detail', $editLocator->detail ?? '') }}</textarea>
                        @error('detail') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                    </label>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button class="inline-flex items-center justify-center rounded-md bg-blue-600 px-6 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" type="submit">{{ isset($editLocator) ? 'Update Locator' : 'File Locator' }}</button>
                </div>
        </form>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-md">
            <x-hris.table-layout
                title="Filed Locators"
                subtitle="Your locator submissions are listed below."
                :paginator="$locators"
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
                    <table class="hris-table" id="filed-locators-table">
                        <thead>
                            <tr>
                                <th style="width:7%"><a href="{{ $sortUrl('application_type') }}" class="{{ $activeClass('application_type') }}">Type</a></th>
                                <th style="width:13%"><a href="{{ $sortUrl('location') }}" class="{{ $activeClass('location') }}">Location</a></th>
                                <th style="width:11%"><a href="{{ $sortUrl('travel_date') }}" class="{{ $activeClass('travel_date') }}">Travel Date</a></th>
                                <th style="width:15%">Time</th>
                                <th style="width:21%">Detail</th>
                                <th style="width:12%"><a href="{{ $sortUrl('status') }}" class="{{ $activeClass('status') }}">Status</a></th>
                                <th style="width:21%">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($locators as $locator)
                                <tr data-employee="{{ $locator->user->name ?? auth()->user()->name }}" data-filed="{{ $locator->created_at ? $locator->created_at->format('M d, Y g:i A') : '' }}" data-status="{{ $locator->status ?? '' }}" data-detail="{{ e($locator->detail ?? '') }}" data-purpose="{{ e($locator->detail ?? '') }}" data-eta="{{ $locator->travel_date ?? '' }} {{ $locator->intended_arrival_time ?? '' }}" data-remarks="{{ e($locator->cancellation_remarks ?? '') }}">
                                    <td>{{ $locator->application_type ?? '-' }}</td>
                                    <td>{{ $locator->location ?? '-' }}</td>
                                    <td>{{ $locator->travel_date?->format('M d, Y') ?? '-' }}</td>
                                    <td>
                                        <div>{{ $locator->intended_departure_time ? \Carbon\Carbon::createFromFormat('H:i:s', $locator->intended_departure_time)->format('g:i A') : '-' }} &ndash; {{ $locator->intended_arrival_time ? \Carbon\Carbon::createFromFormat('H:i:s', $locator->intended_arrival_time)->format('g:i A') : '-' }}</div>
                                        @if($locator->actual_arrival_time)
                                            <div class="text-sm text-slate-500">Actual: {{ \Carbon\Carbon::createFromFormat('H:i:s', $locator->actual_arrival_time)->format('g:i A') }}</div>
                                        @endif
                                    </td>
                                    <td>{{ \Illuminate\Support\Str::limit($locator->detail ?? '-', 60) }}</td>
                                    <td>
                                        <x-hris.status-badge :status="$locator->status" />
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-2">
                                            @if(strtolower((string)$locator->status) === 'pending')
                                                <button type="button" class="hris-btn hris-btn-danger" data-id="{{ $locator->id }}" onclick="cancelLocatorRequest({{ $locator->id }})">Cancel</button>
                                                <a class="hris-btn hris-btn-secondary" href="{{ route('employee.locator.edit', ['locator' => $locator->id]) }}">Edit</a>
                                            @elseif(strtolower((string)$locator->status) === 'approved')
                                                <div class="flex flex-col items-start gap-1 w-full">
                                                    @if(\Illuminate\Support\Facades\Route::has('employee.locator.print.single'))
                                                        <a class="hris-btn hris-btn-primary" href="{{ route('employee.locator.print.single', ['locator' => $locator->id]) }}" target="_blank">Print</a>
                                                    @endif
                                                    @if($locator->cancellation_status === 'Pending Cancellation')
                                                        <span class="text-xs font-medium text-amber-600 break-words">Approved &mdash; Cancellation Requested</span>
                                                    @else
                                                        <form method="POST" action="{{ route('employee.locator.request-cancellation', ['locator' => $locator->id]) }}" id="request-cancellation-locator-form-{{ $locator->id }}" class="inline-block">
                                                            @csrf
                                                            <button type="button" class="hris-btn hris-btn-danger hris-btn-wrap" onclick="promptCancelApprovedLocator({{ $locator->id }})">Request Cancellation</button>
                                                        </form>
                                                        @if($locator->cancellation_status === 'Rejected')
                                                            <span class="text-xs text-red-500 break-words">Cancellation request rejected{{ $locator->cancellation_review_remarks ? ': '.$locator->cancellation_review_remarks : '' }}</span>
                                                        @endif
                                                    @endif
                                                </div>
                                            @else
                                                <button type="button" class="hris-btn hris-btn-secondary" onclick="openLocatorModal({{ $locator->id }})">View</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-4 text-center text-sm text-slate-500">No locator requests found for the selected filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-hris.table-layout>
        </div>
    </div>
@endsection

@section('page_scripts')
    <script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/jquery.dataTables.min.js') }}"></script>
    <script>
        // Ensure SweetAlert is available on this page; load CDN fallback if not present
        (function(){
            if (typeof window.Swal === 'undefined') {
                const s = document.createElement('script');
                s.src = '{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}';
                s.async = false;
                document.head.appendChild(s);
            }
        })();
    </script>
    <script>
        function cancelLocatorRequest(locatorId) {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            function doCancel() {
                fetch('/dashboard/employee/locator/' + locatorId + '/cancel', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({ _token: token })
                }).then(async response => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw new Error(data.message || 'Failed to cancel locator.');
                    }
                    if (window.Swal) {
                        Swal.fire('Cancelled!', data.message || 'Your locator has been cancelled.', 'success').then(() => location.reload());
                    } else {
                        location.reload();
                    }
                }).catch((error) => {
                    if (window.Swal) Swal.fire('Error', error.message || 'Failed to cancel locator.', 'error');
                    else alert(error.message || 'Failed to cancel locator.');
                });
            }

            if (window.Swal) {
                Swal.fire({
                    title: 'Cancel Locator Request?',
                    text: 'Are you sure you want to cancel this locator?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, cancel it',
                    cancelButtonText: 'No'
                }).then((result) => {
                    if (result.isConfirmed) doCancel();
                });
            } else {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/dashboard/employee/locator/' + locatorId + '/cancel';
                const csrf = document.createElement('input'); csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = token; form.appendChild(csrf);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function promptCancelApprovedLocator(locatorId) {
            const form = document.getElementById('request-cancellation-locator-form-' + locatorId);
            if (!form) return;
            const token = form.querySelector('input[name="_token"]').value;

            function doSubmit(reason) {
                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({ reason: reason, _token: token })
                }).then(async response => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Failed to submit cancellation request.');
                    }
                    if (window.Swal) {
                        Swal.fire('Submitted', data.message || 'Cancellation request submitted.', 'success').then(() => location.reload());
                    } else {
                        location.reload();
                    }
                }).catch((error) => {
                    if (window.Swal) Swal.fire('Error', error.message || 'Failed to submit cancellation request.', 'error');
                    else alert(error.message || 'Failed to submit cancellation request.');
                });
            }

            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Request Locator Cancellation',
                    input: 'textarea',
                    inputLabel: 'Reason for cancelling this approved locator',
                    text: 'This will be sent to your Department Head / Administrative Officer for review.',
                    showCancelButton: true,
                    confirmButtonText: 'Submit Request',
                    preConfirm: (v) => { if (!v) Swal.showValidationMessage('A reason is required'); return v; }
                }).then((result) => {
                    if (result.isConfirmed) doSubmit(result.value);
                });
            } else {
                const reason = prompt('Reason for cancelling this approved locator:');
                if (reason) doSubmit(reason);
            }
        }
    </script>
    <script>
        function openViewLocator(id) {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            fetch('/dashboard/employee/locator/data', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(json => {
                    const rows = json.data || [];
                    const loc = rows.find(x => Number(x.id) === Number(id));
                    if (!loc) {
                        if (window.Swal) Swal.fire('Not found', 'Locator details not available.', 'info');
                        else console.info('Locator details not available.');
                        return;
                    }
                    const html = `
                        <p><strong>Type:</strong> ${loc.application_type || '-'} </p>
                        <p><strong>Location:</strong> ${loc.location || '-'} </p>
                        <p><strong>Travel Date:</strong> ${loc.travel_date || '-'} </p>
                        <p><strong>Departure:</strong> ${loc.intended_departure_time || '-'} </p>
                        <p><strong>Arrival:</strong> ${loc.intended_arrival_time || '-'} </p>
                        <p><strong>Detail / Purpose:</strong><br>${(loc.detail || '-') }</p>
                        <p><strong>Status:</strong> ${loc.status || '-'} </p>
                        ${loc.status === 'cancelled' ? `<p><strong>Cancelled At:</strong> ${loc.cancelled_at || '-'}<br><strong>Remarks:</strong> ${loc.cancellation_remarks || '-'}</p>` : ''}
                    `;
                    if (window.Swal) {
                        Swal.fire({ title: 'Locator details', html: html, width: 700 });
                    } else {
                        console.log('Locator details:', loc.detail || '-');
                    }
                }).catch(() => {
                    if (window.Swal) Swal.fire('Error', 'Failed to load locator details.', 'error');
                    else console.error('Failed to load locator details.');
                });
        }
    </script>
    <script>
        function openLocatorModal(id) {
            const row = document.getElementById(`locator-row-${id}`);
            if (!row) {
                if (window.Swal) Swal.fire('Not found', 'Locator details not available.', 'info');
                else console.info('Locator details not available.');
                return;
            }
            const modal = document.getElementById('locatorModal');
            const body = document.getElementById('locator-modal-body');
            const title = document.getElementById('locator-modal-title');
            const actions = document.getElementById('locator-modal-actions');

            const employee = row.getAttribute('data-employee') || '';
            const filed = row.getAttribute('data-filed') || '';
            const status = row.getAttribute('data-status') || '';
            const detail = row.getAttribute('data-detail') || '';
            const purpose = row.getAttribute('data-purpose') || '';
            const eta = row.getAttribute('data-eta') || '';
            const remarks = row.getAttribute('data-remarks') || '';

            title.textContent = 'View details for your locator request';
            body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee Name</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${employee}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Date Filed</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${filed}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Status</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${status}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Detail of Travel</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${detail || '-'}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Purpose of Travel</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${purpose || '-'}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Duration / ETA</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${eta || '-'}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Remarks</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${remarks || '-'}</td></tr>
            </tbody></table>`;

            actions.innerHTML = '';
            if (typeof modal.showModal === 'function') modal.showModal();
        }
    </script>
    @if(session('success'))
    <script>
        (function(){
            const msg = {!! json_encode(session('success')) !!};
            function show(){
                try {
                    if (window.Swal && typeof Swal.fire === 'function') {
                        Swal.fire({ icon: 'success', title: 'Locator filed successfully', text: msg });
                    } else {
                        console.log(msg);
                    }
                } catch (e) { try { console.log(msg); } catch (e) {} }
            }
            if (window.Swal) show();
            else {
                let tries=0; const t=setInterval(()=>{ if (window.Swal){ clearInterval(t); show(); } else if(++tries>20){ clearInterval(t); console.log(msg); } },100);
            }
        })();
    </script>
    @endif
    <script>
        (function(){
            const travelDate = document.getElementById('travel_date');
            const dep = document.getElementById('intended_departure_time');
            const arr = document.getElementById('intended_arrival_time');

            if(travelDate){
                const today = new Date().toISOString().slice(0,10);
                travelDate.setAttribute('min', today);
            }

            if(!dep || !arr) return;

            function syncArrivalMin(){
                if(!dep.value) return;
                // times in "HH:MM" format - set a simple check
                if(arr.value && arr.value < dep.value){
                    arr.value = '';
                }
            }

            dep.addEventListener('change', syncArrivalMin);
            arr.addEventListener('change', function(){ if(dep.value && arr.value < dep.value) { if (window.Swal) Swal.fire({ icon: 'warning', title: 'Invalid time', text: 'Intended arrival cannot be earlier than departure.' }); else console.warn('Intended arrival cannot be earlier than departure.'); arr.value = ''; } });

            // Uppercase transform for specific inputs
            const upperables = document.querySelectorAll('.upper');
            upperables.forEach(el => {
                el.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
            });

            // Ensure uppercasing before submit
            const form = document.querySelector('form.pds-form');
            if(form){
                form.addEventListener('submit', function(){
                    upperables.forEach(el => el.value = (el.value || '').toUpperCase());
                });
            }
        })();
    </script>
@endsection
