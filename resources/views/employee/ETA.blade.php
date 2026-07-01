@extends('dashboards.layout')

@php
    $title = 'Employee Travel Authorization (ETA)';
    $subtitle = 'Access the Employee Travel Authorization (ETA) tools.';
@endphp

@section('page_styles')
    @vite(['resources/css/hris-table.css', 'resources/js/hris-table.js'])
@endsection

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('content')
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-xl shadow-sm">
            <h2 class="text-2xl font-semibold text-slate-900 mb-4">File Employee Travel Authorization</h2>

            @if(session('success'))
            @endif

            <form id="eta-file-form" class="space-y-6" method="POST" action="{{ route('employee.eta.store') }}" data-processing-submit>
                @csrf
                <div class="space-y-6">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Departure Date</span>
                            <input id="departure_date" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" type="date" name="departure_date" required min="{{ date('Y-m-d') }}">
                            @error('departure_date') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Date of Arrival</span>
                            <input id="arrival_date" class="block w-full rounded-md border border-gray-300 bg-gray-100 px-3 py-2 text-sm shadow-sm text-gray-600 cursor-not-allowed focus:outline-none" type="date" name="arrival_date" readonly>
                            <span class="text-slate-500 text-sm mt-1">Automatically set to one day after departure (overnight travel).</span>
                            @error('arrival_date') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Purpose</span>
                            <select class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" name="purpose" required>
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
                            @error('purpose') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Destination</span>
                            <input class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" type="text" name="destination" required placeholder="City, Province/State, Country">
                            @error('destination') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    <div class="space-y-3">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-700">Purpose Details</span>
                            <textarea class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" name="purpose_details" rows="3" placeholder="Provide details of travel or purpose of travel">{{ old('purpose_details') }}</textarea>
                            @error('purpose_details') <div class="text-red-500 text-sm mt-1">{{ $message }}</div> @enderror
                        </label>
                    </div>
                </div>

                <div class="flex justify-end mt-2">
                    <button class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" type="submit">File ETA</button>
                </div>
            </form>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm">
            <x-hris.table-layout
                title="Filed ETAs"
                subtitle="Your submitted ETA records are listed below."
                :paginator="$etas"
                :showExport="false"
                :monthFilterDefault="now()->month"
            >
                <div class="hris-table-wrapper">
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

                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th><a href="{{ $sortUrl('departure_date') }}" class="{{ $activeClass('departure_date') }}">Departure Date</a></th>
                                <th><a href="{{ $sortUrl('arrival_date') }}" class="{{ $activeClass('arrival_date') }}">Date of Arrival</a></th>
                                <th><a href="{{ $sortUrl('destination') }}" class="{{ $activeClass('destination') }}">Destination</a></th>
                                <th><a href="{{ $sortUrl('purpose') }}" class="{{ $activeClass('purpose') }}">Purpose</a></th>
                                <th>Approved By</th>
                                <th><a href="{{ $sortUrl('status') }}" class="{{ $activeClass('status') }}">Status</a></th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($etas as $eta)
                                <tr>
                                    <td>{{ $eta->departure_date }}</td>
                                    <td>{{ $eta->arrival_date ?? '-' }}</td>
                                    <td>{{ $eta->destination }}</td>
                                    <td>
                                        <div>{{ $eta->purpose }}</div>
                                        @if(!empty($eta->purpose_details))
                                            <div class="text-sm text-slate-500">{{ $eta->purpose_details }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $eta->dept_head ?? 'Not assigned' }}</td>
                                    <td>
                                        @php
                                            $status = strtolower((string) $eta->status);
                                            $badgeType = match($status) {
                                                'pending' => 'pending',
                                                'approved' => 'approved',
                                                'rejected' => 'rejected',
                                                'cancelled' => 'cancelled',
                                                default => 'default',
                                            };
                                        @endphp
                                        <x-hris.status-badge :status="$status" />
                                    </td>
                                    <td>
                                        @if($eta->status === 'approved')
                                            <a class="hris-btn hris-btn-secondary" href="{{ route('employee.eta.print.single', ['eta' => $eta->id]) }}" target="_blank">Print</a>
                                        @elseif($eta->status === 'pending')
                                            <form method="POST" action="{{ route('employee.eta.cancel', ['eta' => $eta->id]) }}" id="cancel-eta-form-{{ $eta->id }}" class="inline-block">
                                                @csrf
                                                <button type="button" class="hris-btn hris-btn-danger" onclick="confirmCancelEta({{ $eta->id }})">Cancel</button>
                                            </form>
                                        @else
                                            <span class="text-sm text-gray-500">{{ ucfirst($eta->status) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-4 text-center text-sm text-slate-500">No ETA records found for the selected filters.</td>
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
    <script>
        (function(){
            const dep = document.getElementById('departure_date');
            const arr = document.getElementById('arrival_date');
            if(!dep || !arr) return;

            const today = new Date().toISOString().slice(0,10);
            dep.setAttribute('min', today);

            function syncArrival(){
                if(!dep.value){
                    arr.value = '';
                    return;
                }
                const arrivalDate = new Date(dep.value + 'T00:00:00Z');
                arrivalDate.setUTCDate(arrivalDate.getUTCDate() + 1);
                arr.value = arrivalDate.toISOString().slice(0,10);
            }

            dep.addEventListener('change', syncArrival);
            syncArrival();
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
