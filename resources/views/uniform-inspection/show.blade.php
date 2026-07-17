@extends('dashboards.layout', [
    'title'    => 'Inspection #' . $inspection->id,
    'subtitle' => 'Uniform violation record',
])

@section('page_head')
    @vite(['resources/js/uniform_inspection.js'])
@endsection

@section('top_actions')
    <a href="{{ route('leave-manager.uniform-inspections.index') }}"
       class="hris-btn hris-btn-secondary hris-btn-sm">
        <i class="fas fa-arrow-left fa-fw" aria-hidden="true"></i> Back
    </a>
    <a href="{{ route('leave-manager.uniform-inspections.edit', $inspection) }}"
       class="hris-btn hris-btn-secondary hris-btn-sm" style="margin-left:6px;">
        <i class="fas fa-pencil-alt fa-fw" aria-hidden="true"></i> Edit
    </a>
    <form id="delete-inspection-form" method="POST"
          action="{{ route('leave-manager.uniform-inspections.destroy', $inspection) }}"
          style="display:inline;margin-left:6px;">
        @csrf
        @method('DELETE')
        <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeleteInspection()">
            <i class="fas fa-trash fa-fw" aria-hidden="true"></i> Delete
        </button>
    </form>
@endsection

@section('page_scripts_after')
<script>
function confirmDeleteInspection() {
    var form = document.getElementById('delete-inspection-form');
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete this inspection?',
            html: 'This permanently removes Inspection #{{ $inspection->id }} and <strong>all violation records</strong> in it.<br>Any VL already deducted for it will be refunded automatically.',
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
    } else if (confirm('Permanently delete this inspection and all its records?')) {
        form.submit();
    }
}
</script>
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

{{-- Header card --}}
<div class="lm-section" style="margin-bottom:16px;">
    <div class="lm-section-header" style="margin-bottom:14px;">
        <h3 style="font-size:0.88rem;font-weight:700;color:#1e293b;margin:0;text-transform:uppercase;letter-spacing:0.04em;">
            <i class="fas fa-clipboard-list fa-fw" style="color:#ea580c;" aria-hidden="true"></i>
            Inspection #{{ $inspection->id }}
        </h3>
        <span style="font-size:0.82rem;color:#64748b;">
            Recorded {{ $inspection->created_at->format('M d, Y \a\t h:i A') }}
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;">
        <div>
            <div style="font-size:0.75rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Date</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;">{{ $inspection->inspection_date->format('F d, Y') }}</div>
        </div>
        <div>
            <div style="font-size:0.75rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Time</div>
            <div style="font-size:0.95rem;color:#1e293b;">{{ \Carbon\Carbon::createFromFormat('H:i:s', $inspection->inspection_time)->format('h:i A') }}</div>
        </div>
    </div>

    @if($inspection->remarks)
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;">
            <div style="font-size:0.75rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">General Remarks</div>
            <div style="font-size:0.9rem;color:#374151;">{{ $inspection->remarks }}</div>
        </div>
    @endif
</div>

{{-- Violation details --}}
@php
    $details        = $inspection->details;
    $totalCited     = $details->count();
    $typeBreakdown  = $details->groupBy('violation_type');
    $repeatCount    = $details->where('offense_number', '>=', 2)->count();
@endphp

<div class="lm-section">
    <div class="lm-section-header" style="margin-bottom:16px;">
        <h3 style="font-size:0.88rem;font-weight:700;color:#1e293b;margin:0;text-transform:uppercase;letter-spacing:0.04em;">
            <i class="fas fa-exclamation-triangle fa-fw" style="color:#ea580c;" aria-hidden="true"></i>
            Violation Details
        </h3>
    </div>

    @if($details->isEmpty())
        <p style="color:#64748b;font-size:0.9rem;margin:16px 0;">No violations recorded for this inspection.</p>
    @else

        {{-- Summary stats --}}
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#1e293b;line-height:1;">{{ $totalCited }}</div>
                <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">
                    {{ Str::plural('Employee', $totalCited) }} Cited
                </div>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#1e293b;line-height:1;">{{ $typeBreakdown->count() }}</div>
                <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">
                    Violation {{ Str::plural('Type', $typeBreakdown->count()) }}
                </div>
            </div>
            <div style="background:{{ $repeatCount > 0 ? '#fff7ed' : '#f8fafc' }};border:1px solid {{ $repeatCount > 0 ? '#fed7aa' : '#e2e8f0' }};border-radius:8px;padding:12px 14px;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:{{ $repeatCount > 0 ? '#ea580c' : '#1e293b' }};line-height:1;">{{ $repeatCount }}</div>
                <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">
                    Repeat {{ Str::plural('Offender', $repeatCount) }}
                </div>
            </div>
        </div>

        {{-- Violation type breakdown chips --}}
        @if($typeBreakdown->count() > 0)
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;">
                @foreach($typeBreakdown as $type => $items)
                    <div style="display:inline-flex;align-items:center;gap:6px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:5px 10px;">
                        <span style="font-size:0.78rem;font-weight:600;color:#92400e;">{{ $type }}</span>
                        <span style="background:#92400e;color:#fff;border-radius:999px;font-size:0.68rem;font-weight:700;padding:0 6px;min-width:18px;text-align:center;line-height:1.5;">
                            {{ $items->count() }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Details table --}}
        <div style="overflow-x:auto;">
            <table class="hris-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="width:36px;">#</th>
                        <th>Employee</th>
                        <th style="width:110px;">EmpNo</th>
                        <th style="width:180px;">Violation</th>
                        <th style="text-align:center;width:90px;">Offense #</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($details as $i => $detail)
                        @php
                            $isRepeat = $detail->offense_number >= 2;
                            [$bg, $textColor] = match(true) {
                                $detail->offense_number >= 3 => ['#fee2e2', '#991b1b'],
                                $detail->offense_number === 2 => ['#fff7ed', '#9a3412'],
                                default => ['#f1f5f9', '#475569'],
                            };
                        @endphp
                        <tr style="{{ $isRepeat ? 'border-left:3px solid #f97316;' : '' }}">
                            <td style="color:#94a3b8;font-size:0.82rem;">{{ $i + 1 }}</td>

                            <td>
                                <div style="font-weight:600;font-size:0.9rem;color:#1e293b;">
                                    {{ $detail->employee?->last_name }}, {{ $detail->employee?->first_name }}
                                    @if($isRepeat)
                                        <i class="fas fa-exclamation-circle" style="color:#f97316;font-size:0.75rem;margin-left:3px;" title="Repeat offender" aria-label="Repeat offender"></i>
                                    @endif
                                </div>
                                @if($detail->employee?->department)
                                    <div style="font-size:0.75rem;color:#64748b;">{{ $detail->employee->department->Dept_name }}</div>
                                @endif
                            </td>

                            <td style="font-size:0.82rem;color:#64748b;">{{ $detail->employee?->EmpNo ?? '-' }}</td>

                            <td>
                                <span style="display:inline-flex;align-items:center;padding:2px 8px;background:#fef3c7;color:#92400e;border-radius:999px;font-size:0.75rem;font-weight:600;white-space:nowrap;">
                                    {{ $detail->violation_type }}
                                </span>
                            </td>

                            <td style="text-align:center;">
                                <span style="display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;background:{{ $bg }};color:{{ $textColor }};border-radius:999px;font-size:0.85rem;font-weight:700;">
                                    {{ $detail->offense_number }}
                                </span>
                            </td>

                            <td style="font-size:0.88rem;color:#374151;max-width:220px;">
                                {{ $detail->remarks ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection
