@extends('dashboards.layout')

@php
    $title = 'Locator';
    $subtitle = 'File Locator entries and print locator slips.';
    $locators = $locators ?? collect();
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
            <h2 style="margin-top:0">{{ isset($editLocator) ? 'Update Locator' : 'File Locator' }}</h2>

            @if(session('success'))
                <div class="chip" style="margin-bottom:12px">{{ session('success') }}</div>
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
                        <tr>
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
                                        default => 'badge-default',
                                    };
                                @endphp
                                <span class="badge {{ $locBadgeClass }}">{{ $locator->status ? ucfirst($locator->status) : '' }}</span>
                            </td>
                            <td>
                                @if(!empty($locator->status) && $locator->status === 'pending')
                                    <a class="btn-sm btn-view" href="{{ route('employee.locator.edit', ['locator' => $locator->id]) }}">Update</a>
                                @elseif(!empty($locator->status) && $locator->status === 'approved' && \Illuminate\Support\Facades\Route::has('employee.locator.print.single'))
                                    <a class="btn-sm btn-print" href="{{ route('employee.locator.print.single', ['locator' => $locator->id]) }}" target="_blank">Print</a>
                                @else
                                    <span class="muted">{{ $locator->status ? ucfirst($locator->status) : 'Pending approval' }}</span>
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
        $(function(){
            if ($.fn.DataTable && $.fn.DataTable.isDataTable && $.fn.DataTable.isDataTable('#locator-table')) {
                return;
            }
            $('#locator-table').DataTable({ responsive:true, paging:false, info:false, pageLength:10 });
        });
    </script>
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
            arr.addEventListener('change', function(){ if(dep.value && arr.value < dep.value) { alert('Intended arrival cannot be earlier than departure.'); arr.value = ''; } });

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
