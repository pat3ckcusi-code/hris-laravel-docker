@extends('dashboards.layout')

@php
    $title = 'Employee Travel Authorization (ETA)';
    $subtitle = 'Access the Employee Travel Authorization (ETA) tools.';
@endphp

@section('page_styles')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
@endsection

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('content')
    <div style="display:flex; flex-direction:column; gap:12px">
        <div class="tile">
            <h2 style="margin-top:0">File Employee Travel Authorization</h2>

            @if(session('success'))
            @endif

            <form id="eta-file-form" class="pds-form" method="POST" action="{{ route('employee.eta.store') }}" data-processing-submit>
                @csrf
                <div class="pds-section">
                    <div class="field-grid two">
                        <label>
                            Departure Date
                            <input id="departure_date" class="form-input" type="date" name="departure_date" required min="{{ date('Y-m-d') }}">
                            @error('departure_date') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>

                        <label>
                            Date of Arrival
                            <input id="arrival_date" class="form-input" type="date" name="arrival_date" min="{{ date('Y-m-d') }}">
                            @error('arrival_date') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <div class="field-grid two">                        
                        <label>
                            Purpose
                            <select class="form-input" name="purpose" required>
                                <option value="">Select purpose</option>
                                <option value="Audit-Inspection-Licensing" {{ old('purpose') == 'Audit-Inspection-Licensing' ? 'selected' : '' }}>Audit-Inspection-Licensing</option>
                                <option value="Client Support" {{ old('purpose') == 'Client Support' ? 'selected' : '' }}>Client Support</option>
                                <option value="Conference" {{ old('purpose') == 'Conference' ? 'selected' : '' }}>Conference</option>
                                <option value="Construction Repair Maintenance" {{ old('purpose') == 'Construction Repair Maintenance' ? 'selected' : '' }}>Construction Repair Maintenance</option>
                                <option value="Economic Development" {{ old('purpose') == 'Economic Development' ? 'selected' : '' }}>Economic Development</option>
                                <option value="Legal-Law Enforcement" {{ old('purpose') == 'Legal-Law Enforcement' ? 'selected' : '' }}>Legal-Law Enforcement</option>
                                <option value="Legislator" {{ old('purpose') == 'Legislator' ? 'selected' : '' }}>Legislator</option>
                                <option value="Meeting" {{ old('purpose') == 'Meeting' ? 'selected' : '' }}>Meeting</option>
                                <option value="Training" {{ old('purpose') == 'Training' ? 'selected' : '' }}>Training</option>
                                <option value="Seminar" {{ old('purpose') == 'Seminar' ? 'selected' : '' }}>Seminar</option>
                                <option value="General Expense/Other" {{ old('purpose') == 'General Expense/Other' ? 'selected' : '' }}>General Expense/Other</option>
                            </select>
                            @error('purpose') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>

                        <label>
                            Destination
                            <input class="form-input" type="text" name="destination" required placeholder="City, Province/State, Country">
                            @error('destination') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <div class="field-grid">
                        <label>
                            Purpose Details (ex: "Proceeding to HR Department for Technical Support.")
                            <textarea class="form-input" name="purpose_details" rows="3" placeholder="Provide details of travel or purpose of travel">{{ old('purpose_details') }}</textarea>
                            @error('purpose_details') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror
                        </label>
                    </div>
                </div>

                <div class="actions" style="margin-top:12px">
                    <button class="btn" type="submit">File ETA</button>
                </div>
            </form>
        </div>

        <div class="tile">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px">
                <h2 style="margin:0">Filed ETAs</h2>
                <div>
                    <a href="{{ route('dashboard.employee.eta') }}">All</a>
                    &nbsp;|&nbsp;
                    <a href="{{ route('dashboard.employee.eta', ['filter' => 'weekly']) }}">Weekly</a>
                    &nbsp;|&nbsp;
                    <a href="{{ route('dashboard.employee.eta', ['filter' => 'monthly']) }}">Monthly</a>
                </div>
            </div>

            <div style="overflow:auto">
                <table id="eta-table" class="display leave-table" style="width:100%">
                    <thead>
                    <tr>
                        <th>Departure Date</th>
                        <th>Date of Arrival</th>
                        <th>Destination</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($etas as $eta)
                            <tr>
                                <td>{{ $eta->departure_date }}</td>
                                <td>{{ $eta->arrival_date ?? '-' }}</td>
                                <td>{{ $eta->destination }}</td>
                                <td>
                                    <div>{{ $eta->purpose }}</div>
                                    @if(!empty($eta->purpose_details))
                                        <div class="muted" style="font-size:0.9rem">{{ $eta->purpose_details }}</div>
                                    @endif
                                </td>
                                <td>{{ $eta->dept_head ?? 'Not assigned' }}</td>
                                <td>
                                    @php
                                        $etaBadgeClass = match(strtolower((string) $eta->status)) {
                                            'pending' => 'badge-pending',
                                            'approved' => 'badge-approved',
                                            'rejected' => 'badge-rejected',
                                            'cancelled' => 'badge-cancelled',
                                            default => 'badge-default',
                                        };
                                    @endphp
                                    <span class="badge {{ $etaBadgeClass }}">{{ $eta->status ? ucfirst($eta->status) : '' }}</span>
                                </td>
                            <td>
                                @if($eta->status === 'approved')
                                    <a class="btn-sm btn-print" href="{{ route('employee.eta.print.single', ['eta' => $eta->id]) }}" target="_blank">Print</a>
                                @elseif($eta->status === 'pending')
                                    <form method="POST" action="{{ route('employee.eta.cancel', ['eta' => $eta->id]) }}" id="cancel-eta-form-{{ $eta->id }}" style="display:inline">
                                        @csrf
                                        <button type="button" class="btn-sm btn-reject" onclick="confirmCancelEta({{ $eta->id }})">Cancel</button>
                                    </form>
                                @else
                                    <span class="muted">{{ ucfirst($eta->status) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    </table>
                    <div style="margin-top:10px; display:flex; justify-content:flex-end">
                        {{ $etas->appends(request()->query())->links() }}
                    </div>
            </div>
        </div>
    </div>
@endsection

@section('page_scripts')
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>
        $(function(){
            if ($.fn.DataTable && $.fn.DataTable.isDataTable && $.fn.DataTable.isDataTable('#eta-table')) {
                return;
            }
            $('#eta-table').DataTable({
                responsive: true,
                paging: false,
                info: false,
                searching: false,
            });
        });
    </script>
    <script>
        (function(){
            const dep = document.getElementById('departure_date');
            const arr = document.getElementById('arrival_date');
            if(!dep || !arr) return;

            const today = new Date().toISOString().slice(0,10);
            dep.setAttribute('min', today);
            if(!arr.getAttribute('min')) arr.setAttribute('min', today);

            function syncArrivalMin(){
                const depVal = dep.value || today;
                arr.setAttribute('min', depVal);
                if(arr.value && arr.value < depVal){
                    arr.value = '';
                }
            }

            dep.addEventListener('change', syncArrivalMin);
            syncArrivalMin();
        })();
    </script>
    @if(session('success'))
    <script>
        try {
            if (window.Swal && typeof Swal.fire === 'function') {
                Swal.fire({ icon: 'success', title: 'Success', text: {!! json_encode(session('success')) !!} });
            }
        } catch (e) {}
    </script>
    @endif
    <script>
        function ensureSwal() {
            return new Promise(async (resolve) => {
                if (window.Swal && typeof Swal.fire === 'function') return resolve(window.Swal);

                const existing = document.querySelector('script[data-swal-fallback]');
                if (existing) {
                    existing.addEventListener('load', () => resolve(window.Swal || null));
                    existing.addEventListener('error', () => resolve(null));
                    return;
                }

                const localPaths = [
                    '/js/app.js'
                ];

                const tryLoad = (src) => new Promise((res) => {
                    const s = document.createElement('script');
                    s.src = src;
                    s.dataset.swalFallback = '1';
                    s.onload = () => res(window.Swal || null);
                    s.onerror = () => res(null);
                    document.head.appendChild(s);
                });

                for (const p of localPaths) {
                    try {
                        const loaded = await tryLoad(p);
                        if (loaded) return resolve(window.Swal || null);
                    } catch (e) {}
                }

                // No external CDN fallback; rely on local build assets provided by Vite
                return resolve(null);
            });
        }

        async function confirmCancelEta(id) {
            const form = document.getElementById('cancel-eta-form-' + id);
            if (!form) return;
            const token = form.querySelector('input[name="_token"]').value;
            const SwalLib = await ensureSwal();
            if (SwalLib) {
                SwalLib.fire({ title: 'Cancel ETA?', text: 'This will cancel your ETA request.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, cancel' })
                .then((r) => {
                    if (r.isConfirmed) {
                        fetch(form.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: new URLSearchParams({ _token: token }) })
                        .then(res => res.json())
                        .then(data => {
                            SwalLib.fire({ icon: 'success', text: data.message || 'Cancelled' }).then(()=> location.reload());
                        })
                        .catch(()=> SwalLib.fire({ icon: 'error', text: 'Failed to cancel' }));
                    }
                });
            } else {
                if (confirm('Cancel this ETA?')) form.submit();
            }
        }
    </script>
    <script>
        (function(){
            const form = document.getElementById('eta-file-form');
            if (!form) return;

            const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');

            async function handleConfirmAndSubmit() {
                let SwalLib = null;
                try {
                    SwalLib = await ensureSwal();

                    if (SwalLib) {
                        const result = await SwalLib.fire({ title: 'File ETA', text: 'Are you sure you want to file this application?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, file' });
                        if (!result.isConfirmed) return;

                        SwalLib.fire({ title: 'Filing ETA', html: 'Please wait...', allowOutsideClick: false, didOpen: () => { SwalLib.showLoading(); } });
                    } else {
                        if (!confirm('Are you sure you want to file this application?')) return;
                    }

                    const formData = new FormData(form);
                    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
                    const tokenInput = form.querySelector('input[name="_token"]');
                    if (tokenInput) headers['X-CSRF-TOKEN'] = tokenInput.value;

                    const resp = await fetch(form.action, { method: (form.method || 'POST').toUpperCase(), headers, body: formData, credentials: 'same-origin' });

                    if (SwalLib) SwalLib.close();

                    if (resp.redirected) {
                        window.location = resp.url;
                        return;
                    }

                    const text = await resp.text();
                    let data = null;
                    try { data = JSON.parse(text); } catch (e) { data = null; }

                    const SwalShow = async (opts) => {
                        const S = await ensureSwal();
                        if (S) return S.fire(opts);
                        alert((opts.title ? opts.title + '\n' : '') + (opts.text || ''));
                    };

                    if (resp.status === 422 && data && data.errors) {
                        const msgs = [];
                        for (const k in data.errors) if (data.errors[k]) msgs.push(...data.errors[k]);
                        await SwalShow({ icon: 'error', title: 'Validation error', text: msgs.join('\n') });
                        return;
                    }

                    if (data && (data.success || data.message)) {
                        await SwalShow({ icon: 'success', title: 'Success', text: data.message || data.success });
                        if (data.redirect) return window.location = data.redirect;
                        return window.location.reload();
                    }

                    if (resp.ok) {
                        await SwalShow({ icon: 'success', title: 'Success', text: 'ETA filed successfully' });
                        return window.location.reload();
                    }

                    await SwalShow({ icon: 'error', title: 'Error', text: 'Failed to file ETA' });

                } catch (err) {
                    if (SwalLib) SwalLib.close();
                    form.submit();
                }
            }

            if (submitBtn) {
                submitBtn.addEventListener('click', function (e) {
                    if (form.dataset.confirmed === '1') {
                        delete form.dataset.confirmed;
                        return;
                    }

                    if (!form.checkValidity()) {
                        form.reportValidity();
                        return;
                    }

                    e.preventDefault();
                    handleConfirmAndSubmit();
                }, true);
            } else {
                form.addEventListener('submit', async function (e) {
                    if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
                    if (!form.checkValidity()) { return; }
                    e.preventDefault();
                    await handleConfirmAndSubmit();
                }, true);
            }
        })();
    </script>
@endsection
