@php $empName = trim("{$user->last_name}, {$user->first_name}"); @endphp
@extends('dashboards.layout', [
    'title' => 'Shift Assignment History',
    'subtitle' => "Full expired-assignment history for {$empName}.",
])

@section('page_head')
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<style>
.sched-history-page-list { list-style:none; margin:0; padding:0; font-size:.88rem; color:#1f2937; }
.sched-history-page-list li {
    display:flex; align-items:center; justify-content:space-between; gap:.75rem;
    padding:.6rem 0; border-bottom:1px solid #e5e7eb;
}
.sched-history-page-list .sched-shift-dates { font-size:.78rem; color:#94a3b8; font-weight:400; }
.sched-history-empty { font-size:.85rem; color:#94a3b8; padding:1rem 0; }

/* Edit panel reused from the main Shift Assignment screen. */
.sched-shift-select { padding:.4rem .55rem; border:1px solid #cbd5e1; border-radius:.4rem; font-size:.82rem; min-width:13rem; background:#fff; }
.sched-days-group { display:flex; gap:.5rem; flex-wrap:wrap; }
.sched-day-chip { display:flex; align-items:center; gap:.25rem; font-size:.75rem; color:#475569; font-weight:500; cursor:pointer; }
.sched-edit-shift { display:inline-block; }
.sched-edit-shift summary { cursor:pointer; font-size:.72rem; font-weight:600; color:#0369a1; list-style:none; }
.sched-edit-shift summary::-webkit-details-marker { display:none; }
.sched-edit-shift-form {
    display:flex; flex-direction:column; gap:.4rem; margin-top:.5rem;
    padding:.6rem; border:1px dashed #7dd3fc; border-radius:.5rem; background:#f0f9ff; min-width:14rem;
}
.sched-add-dates { display:flex; gap:.4rem; }
.sched-add-dates input { flex:1; padding:.35rem .45rem; border:1px solid #cbd5e1; border-radius:.35rem; font-size:.78rem; }
.sched-edit-submit { align-self:flex-start; padding:.35rem .7rem !important; font-size:.78rem !important; background:#0369a1 !important; border-color:#0369a1 !important; }
</style>
@endsection

@section('content')

<div class="sched-toolbar">
    <a href="{{ route('attendance.schedules') }}" class="hris-btn">&larr; Back to Shift Assignment</a>
</div>

<h3 style="margin:0 0 1rem;">{{ $empName }}</h3>

@if ($assignments->isEmpty())
    <p class="sched-history-empty">No expired shift assignments on file.</p>
@else
    <ul class="sched-history-page-list">
        @foreach ($assignments as $row)
            @php
                $rowShiftLabel = $row->shift?->name ?? 'Standard Day';
                $rowDaysLabel = \App\Models\Shift::daysOfWeekLabel($row->days_of_week) ?? 'Every day';
                $rowDateLabel = $row->effective_from->toFormattedDateString().' – '.$row->effective_until->toFormattedDateString();
            @endphp
            <li>
                <span>{{ $rowShiftLabel }} — {{ $rowDaysLabel }} <span class="sched-shift-dates">({{ $rowDateLabel }})</span></span>
                @include('attendance.schedules._edit-shift-form', ['emp' => $user, 'empName' => $empName, 'row' => $row, 'shifts' => $shifts])
            </li>
        @endforeach
    </ul>

    <div class="pagination-wrap" style="padding:.75rem 0;">{{ $assignments->links() }}</div>
@endif

@endsection

@section('page_scripts')
<script>
document.querySelectorAll('.sched-edit-shift-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name  = form.dataset.name || 'this employee';
        var dates = form.dataset.dates || 'this period';
        Swal.fire({
            icon: 'warning',
            title: 'Save this correction?',
            html: 'This corrects <b>' + name + '</b>&rsquo;s already-recorded assignment for <b>' + dates
                + '</b>. Existing time records in that range will be recomputed.',
            showCancelButton: true,
            confirmButtonText: 'Yes, save correction',
            confirmButtonColor: '#0369a1',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) form.submit(); });
    });
});
</script>
@endsection
