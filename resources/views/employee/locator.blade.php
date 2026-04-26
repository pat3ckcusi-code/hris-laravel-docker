@extends('dashboards.layout')

@php
    $title = 'Locator';
    $subtitle = 'File Locator entries and print locator slips.';
    $locators = $locators ?? collect();
@endphp

@section('page_styles')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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
@endsection

@section('content')
    <div style="display:flex; flex-direction:column; gap:12px">
        <div class="tile">
            <h2 style="margin-top:0">{{ isset($editLocator) ? 'Update Locator' : 'File Locator' }}</h2>

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
                <div class="pds-section">
                    <div class="field-grid two">
                        <label>
                            Application Type
                            <select class="form-input" name="application_type" required>
                                <option value="">Select type</option>
                                <option value="Official" {{ old('application_type', $editLocator->application_type ?? '') == 'Official' ? 'selected' : '' }}>Official</option>
                                <option value="Personal" {{ old('application_type', $editLocator->application_type ?? '') == 'Personal' ? 'selected' : '' }}>Personal</option>
                            </select>
                            @error('application_type') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>

                        <label>
                            Location
                            <input class="form-input upper" type="text" name="location" required placeholder="City, Province/State, Country" value="{{ old('location', $editLocator->location ?? '') }}">
                            @error('location') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <div class="field-grid three">
                        <label>
                            Date of Travel
                            <input id="travel_date" class="form-input" type="date" name="travel_date" required min="{{ date('Y-m-d') }}" value="{{ old('travel_date', $editLocator->travel_date ?? '') }}">
                            @error('travel_date') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>

                        <label>
                            Intended Time of Departure
                            <input id="intended_departure_time" class="form-input" type="time" name="intended_departure_time" required value="{{ old('intended_departure_time', $editLocator->intended_departure_time ?? '') }}">
                            @error('intended_departure_time') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>

                        <label>
                            Intended Time of Arrival
                            <input id="intended_arrival_time" class="form-input" type="time" name="intended_arrival_time" required value="{{ old('intended_arrival_time', $editLocator->intended_arrival_time ?? '') }}">
                            @error('intended_arrival_time') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <div class="field-grid">
                        <label>
                            Detail of Travel / Purpose of Travel
                            <textarea class="form-input upper" name="detail" rows="3" required>{{ old('detail', $editLocator->detail ?? '') }}</textarea>
                            @error('detail') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    
                </div>

                <div class="actions" style="margin-top:12px">
                    <button class="btn" type="submit">{{ isset($editLocator) ? 'Update Locator' : 'File Locator' }}</button>
                </div>
            </form>
        </div>

        <div class="tile">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px">
                <h2 style="margin:0">Filed Locators</h2>
                <div>
                    <a href="{{ route('dashboard.employee.locator') }}">All</a>
                </div>
            </div>

            <div style="overflow:auto">
                <table id="locator-table" class="display leave-table" style="width:100%">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Date of Travel</th>
                        <th>Intended Departure</th>
                        <th>Intended Arrival</th>
                        <th>Detail / Purpose</th>
                        <th>Actual Arrival</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($locators as $locator)
                        <tr id="locator-row-{{ $locator->id }}"
                            data-employee="{{ $locator->user->name ?? auth()->user()->name }}"
                            data-filed="{{ $locator->created_at ? $locator->created_at->format('M d, Y g:i A') : '' }}"
                            data-status="{{ $locator->status ?? '' }}"
                            data-detail="{{ e($locator->detail ?? '') }}"
                            data-purpose="{{ e($locator->detail ?? '') }}"
                            data-eta="{{ $locator->travel_date ?? '' }} {{ $locator->intended_arrival_time ?? '' }}"
                            data-remarks="{{ e($locator->cancellation_remarks ?? '') }}">
                            <td>{{ $locator->application_type ?? '-' }}</td>
                            <td>{{ $locator->location ?? '-' }}</td>
                            <td>{{ $locator->travel_date ?? '-' }}</td>
                            <td>{{ $locator->intended_departure_time ? \Carbon\Carbon::createFromFormat('H:i:s', $locator->intended_departure_time)->format('g:i A') : '-' }}</td>
                            <td>{{ $locator->intended_arrival_time ? \Carbon\Carbon::createFromFormat('H:i:s', $locator->intended_arrival_time)->format('g:i A') : '-' }}</td>
                            <td style="max-width:220px">{{ \Illuminate\Support\Str::limit($locator->detail ?? '-', 80) }}</td>
                            <td>{{ $locator->actual_arrival_time ? \Carbon\Carbon::createFromFormat('H:i:s', $locator->actual_arrival_time)->format('g:i A') : '-' }}</td>
                            <td>
                                @php
                                    $locBadgeClass = match(strtolower((string) ($locator->status ?? ''))) {
                                        'pending' => 'badge-pending',
                                        'approved' => 'badge-approved',
                                        'rejected' => 'badge-rejected',
                                        'cancelled' => 'badge-default',
                                        default => 'badge-default',
                                    };
                                @endphp
                                <span class="badge {{ $locBadgeClass }}">{{ $locator->status ? ucfirst($locator->status) : '' }}</span>
                            </td>
                            <td>
                                @if(strtolower((string)$locator->status) === 'pending')
                                    <button type="button" class="btn-sm btn-danger cancel-locator" data-id="{{ $locator->id }}">Cancel</button>
                                    <a class="btn-sm btn-view" href="{{ route('employee.locator.edit', ['locator' => $locator->id]) }}">Update</a>
                                @elseif(strtolower((string)$locator->status) === 'approved' && \Illuminate\Support\Facades\Route::has('employee.locator.print.single'))
                                    <a class="btn-sm btn-print" href="{{ route('employee.locator.print.single', ['locator' => $locator->id]) }}" target="_blank">Print</a>
                                @else
                                    <button type="button" class="btn-sm btn-view" onclick="openLocatorModal({{ $locator->id }})">View</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div style="margin-top:10px">{{ $locators->links() }}</div>
            </div>
        </div>
    </div>
@endsection

@section('page_scripts')
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>
        // Ensure SweetAlert is available on this page; load CDN fallback if not present
        (function(){
            if (typeof window.Swal === 'undefined') {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
                s.async = false;
                document.head.appendChild(s);
            }
        })();
    </script>
    <script>
        $(function(){
            if ($.fn.DataTable && $.fn.DataTable.isDataTable && $.fn.DataTable.isDataTable('#locator-table')) {
                return;
            }
            $('#locator-table').DataTable({ responsive:true, paging:false, info:false, pageLength:10 });
        });
    </script>
    <script>
        // Cancel locator via SweetAlert confirmation
        $(document).on('click', '.cancel-locator', function () {
            const btn = $(this);
            const locatorId = btn.data('id');
            if (!locatorId) return;
            const token = $('meta[name="csrf-token"]').attr('content');

            if (window.Swal) {
                Swal.fire({
                    title: 'Cancel Locator Request?',
                    text: 'Are you sure you want to cancel this locator?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, cancel it',
                    cancelButtonText: 'No'
                }).then((result) => {
                    if (!result.isConfirmed) return;
                    $.ajax({
                        url: '/dashboard/employee/locator/' + locatorId + '/cancel',
                        type: 'POST',
                        data: { _token: token },
                        success: function (resp) {
                            Swal.fire('Cancelled!', resp.message || 'Your locator has been cancelled.', 'success').then(() => location.reload());
                        },
                        error: function (xhr) {
                            let msg = 'Failed to cancel locator.';
                            try { msg = xhr.responseJSON?.message || msg; } catch (e) {}
                            Swal.fire('Error', msg, 'error');
                        }
                    });
                });
            } else {
                // No native confirm — submit directly when Swal is not present
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/dashboard/employee/locator/' + locatorId + '/cancel';
                const csrf = document.createElement('input'); csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = token; form.appendChild(csrf);
                document.body.appendChild(form);
                form.submit();
            }
        });
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
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Detail of Travel</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${detail || '—'}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Purpose of Travel</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${purpose || '—'}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Duration / ETA</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${eta || '—'}</td></tr>
                <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Remarks</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${remarks || '—'}</td></tr>
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
                // times in "HH:MM" format — set a simple check
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
