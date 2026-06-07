@extends('dashboards.layout', [
    'title' => 'Import Attendance Logs',
    'subtitle' => 'Pull biometric punch logs from the integration API for a date range.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    @if(session('success'))
        <div class="hrm-alert hrm-alert-success" style="margin-bottom:1rem;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="hrm-alert hrm-alert-error" style="margin-bottom:1rem;">
            {{ $errors->first() }}
        </div>
    @endif

    <section class="hrm-module">
        <form class="hrm-form-card" method="POST" action="{{ route('hr-manager.attendance.import.store') }}">
            @csrf

            <h4>Date Range</h4>
            <p style="color:#666;font-size:.875rem;margin-bottom:1rem;">
                The import fetches all punch records within the selected range and queues a
                background job to process them. Check the
                <a href="{{ route('hr-manager.audit') }}">audit log</a> for results.
            </p>

            <div class="hrm-signatory-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label for="from_date">From</label>
                    <input type="date" class="hrm-input" id="from_date" name="from_date"
                           value="{{ old('from_date') }}" required>
                </div>
                <div class="form-group">
                    <label for="to_date">To</label>
                    <input type="date" class="hrm-input" id="to_date" name="to_date"
                           value="{{ old('to_date') }}" required>
                </div>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label for="dept_id">Department <span style="color:#888;font-weight:400;">(optional — leave blank to import all)</span></label>
                <select class="hrm-input" id="dept_id" name="dept_id">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->Dept_id }}"
                            {{ old('dept_id') == $dept->Dept_id ? 'selected' : '' }}>
                            {{ $dept->Dept_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="margin-top:1.5rem;">
                <button type="submit" class="hrm-btn hrm-btn-primary">
                    Queue Import
                </button>
            </div>
        </form>
    </section>
@endsection
