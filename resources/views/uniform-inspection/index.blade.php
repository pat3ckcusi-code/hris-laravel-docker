@extends('dashboards.layout', [
    'title'    => 'Uniform Inspections',
    'subtitle' => 'Record and track employee uniform violations',
])

@section('page_head')
    @vite(['resources/js/uniform_inspection.js'])
@endsection

@section('top_actions')
    <a href="{{ route('leave-manager.uniform-inspections.create') }}" class="hris-btn hris-btn-primary hris-btn-sm">
        <i class="fas fa-plus fa-fw" aria-hidden="true"></i> New Inspection
    </a>
@endsection

@section('content')

@if(session('success'))
    <div style="background:#f0fdf4;border:1px solid #86efac;border-left:4px solid #16a34a;border-radius:8px;padding:12px 16px;color:#166534;font-size:0.9rem;margin-bottom:18px;">
        <i class="fas fa-check-circle fa-fw"></i> {{ session('success') }}
    </div>
@endif

@if(session('warning'))
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #ea580c;border-radius:8px;padding:12px 16px;color:#9a3412;font-size:0.9rem;margin-bottom:18px;">
        <i class="fas fa-exclamation-triangle fa-fw"></i> {{ session('warning') }}
    </div>
@endif

{{-- Filter bar --}}
<form method="GET" action="{{ route('leave-manager.uniform-inspections.index') }}"
      class="filter-bar" style="flex-wrap:wrap;gap:14px 0;">

    <div class="filter-field" style="flex:1 1 140px;min-width:130px;">
        <label>Date</label>
        <input type="date" name="date" class="form-control form-control-sm"
               value="{{ request('date') }}">
    </div>

    <div class="filter-field" style="flex:2 1 180px;min-width:160px;">
        <label>Department</label>
        <select name="department_id" class="form-control form-control-sm">
            <option value="">All departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->Dept_id }}" @selected(request('department_id') == $dept->Dept_id)>
                    {{ $dept->Dept_name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="filter-field" style="flex:1 1 160px;min-width:150px;">
        <label>Violation Type</label>
        <select name="violation_type" class="form-control form-control-sm">
            <option value="">All types</option>
            @foreach($violationTypes as $type)
                <option value="{{ $type }}" @selected(request('violation_type') === $type)>{{ $type }}</option>
            @endforeach
        </select>
    </div>

    <div class="filter-field" style="flex:2 1 180px;min-width:160px;position:relative;">
        <label>Employee</label>
        <input type="text" id="idxEmpSearch" class="form-control form-control-sm"
               placeholder="Type name or EmpNo…" autocomplete="off"
               value="{{ request('employee_id') ? ($employees->firstWhere('id', request('employee_id'))?->last_name . ', ' . $employees->firstWhere('id', request('employee_id'))?->first_name) : '' }}">
        <input type="hidden" name="employee_id" id="idxEmpId" value="{{ request('employee_id') }}">
        <div id="idxEmpSuggestions" class="list-group"
             style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:220px;overflow:auto;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
    </div>

    <div class="filter-field" style="flex:0 0 auto;min-width:auto;border-right:none;padding-right:0;margin-right:0;justify-content:flex-end;padding-top:1.4rem;">
        <div style="display:flex;align-items:center;gap:8px;">
            <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm">Filter</button>
            <a href="{{ route('leave-manager.uniform-inspections.index') }}"
               style="font-size:0.82rem;color:#64748b;text-decoration:none;white-space:nowrap;">Clear</a>
        </div>
    </div>
</form>

{{-- Table --}}
<section class="lm-section">
    <div class="lm-section-header">
        <h3 style="font-size:0.88rem;font-weight:700;color:#1e293b;margin:0;text-transform:uppercase;letter-spacing:0.04em;">
            Inspection Records
        </h3>
        <span style="font-size:0.82rem;color:#64748b;">
            {{ $inspections->total() }} {{ Str::plural('record', $inspections->total()) }}
        </span>
    </div>

    @if($inspections->isEmpty())
        <p style="color:#64748b;font-size:0.9rem;margin:24px 0;">
            No inspections found.
            <a href="{{ route('leave-manager.uniform-inspections.create') }}" style="color:#ea580c;">Create the first one.</a>
        </p>
    @else
        <div style="overflow-x:auto;">
            <table class="hris-table" style="width:100%">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th style="width:140px;">Date &amp; Time</th>
                        <th>Employees Cited</th>
                        <th style="width:200px;">Violation Types</th>
                        <th style="text-align:center;width:130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($inspections as $i => $inspection)
                        @php
                            $details  = $inspection->details;
                            $empCount = $details->count();
                            $shown    = $details->take(3);
                            $extra    = $empCount - $shown->count();
                            $types    = $details->pluck('violation_type')->unique()->values();
                        @endphp
                        <tr>
                            <td style="color:#94a3b8;font-size:0.82rem;">{{ $inspections->firstItem() + $i }}</td>

                            {{-- Date & Time --}}
                            <td>
                                <div style="font-weight:600;font-size:0.9rem;color:#1e293b;white-space:nowrap;">
                                    {{ $inspection->inspection_date->format('M d, Y') }}
                                </div>
                                <div style="font-size:0.78rem;color:#64748b;white-space:nowrap;">
                                    {{ \Carbon\Carbon::createFromFormat('H:i:s', $inspection->inspection_time)->format('h:i A') }}
                                </div>
                            </td>

                            {{-- Employees Cited --}}
                            <td>
                                @if($empCount === 0)
                                    <span style="color:#94a3b8;font-size:0.85rem;">-</span>
                                @else
                                    <div style="display:flex;flex-direction:column;gap:3px;">
                                        @foreach($shown as $detail)
                                            <div style="font-size:0.85rem;color:#1e293b;white-space:nowrap;">
                                                {{ $detail->employee?->last_name }}, {{ $detail->employee?->first_name }}
                                                @if($detail->offense_number >= 2)
                                                    <span style="display:inline-flex;align-items:center;padding:0 5px;background:#fff7ed;color:#9a3412;border:1px solid #fdba74;border-radius:999px;font-size:0.7rem;font-weight:700;margin-left:3px;">
                                                        Offense #{{ $detail->offense_number }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endforeach
                                        @if($extra > 0)
                                            <div style="font-size:0.78rem;color:#64748b;">+{{ $extra }} more</div>
                                        @endif
                                    </div>
                                @endif
                            </td>

                            {{-- Violation Types --}}
                            <td>
                                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                                    @foreach($types as $type)
                                        <span style="display:inline-flex;align-items:center;padding:2px 8px;background:#fef3c7;color:#92400e;border-radius:999px;font-size:0.72rem;font-weight:600;white-space:nowrap;">
                                            {{ $type }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>

                            {{-- Actions --}}
                            <td style="text-align:center;white-space:nowrap;">
                                <a href="{{ route('leave-manager.uniform-inspections.show', $inspection) }}"
                                   class="hris-btn hris-btn-secondary hris-btn-sm">
                                    <i class="fas fa-eye fa-fw" aria-hidden="true"></i> View
                                </a>
                                <a href="{{ route('leave-manager.uniform-inspections.edit', $inspection) }}"
                                   class="hris-btn hris-btn-secondary hris-btn-sm" style="margin-left:4px;">
                                    <i class="fas fa-pencil-alt fa-fw" aria-hidden="true"></i> Edit
                                </a>
                                <form id="delete-inspection-{{ $inspection->id }}" method="POST"
                                      action="{{ route('leave-manager.uniform-inspections.destroy', $inspection) }}"
                                      style="display:inline;margin-left:4px;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm"
                                            onclick="confirmDeleteInspection({{ $inspection->id }})">
                                        <i class="fas fa-trash fa-fw" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px;">
            {{ $inspections->links() }}
        </div>
    @endif
</section>

@endsection

@section('page_scripts_after')
<script>
function confirmDeleteInspection(id) {
    var form = document.getElementById('delete-inspection-' + id);
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete this inspection?',
            html: 'This removes the inspection and <strong>all violation records</strong> in it.<br>Any VL already deducted for it will be refunded automatically.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-trash fa-fw"></i> Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            reverseButtons: true,
            focusCancel: true,
        }).then(function (result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    } else if (confirm('Delete this inspection and all violation records?')) {
        form.submit();
    }
}
</script>
@endsection
