@extends('dashboards.layout', [
    'title'    => 'Shift Assignment',
    'subtitle' => 'Assign a work-shift template to each employee. Unassigned employees follow the standard day shift.',
])

@section('page_head')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.sched-flag { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; }
.sched-save-btn { white-space: nowrap; }
.sched-shift-select { padding: .3rem .45rem; border: 1px solid #cbd5e1; border-radius: .35rem; font-size: .8rem; min-width: 12rem; }
.sched-badge {
    display: inline-block; padding: .15rem .5rem; border-radius: 9999px;
    font-size: .7rem; font-weight: 600; background: #e5e7eb; color: #374151;
}
.sched-badge-night { background: #e0e7ff; color: #3730a3; }
</style>
@endsection

@section('content')

<div style="margin:0 0 1rem;">
    <a href="{{ route('attendance.shifts') }}" class="hris-btn">Manage Shift Templates →</a>
</div>

<div class="hris-table-card" id="sched-list-card">

    <div class="hris-table-filters hris-filters-sticky">
        <form method="GET" action="{{ route('attendance.schedules') }}" class="hris-filter-left" style="flex-wrap:wrap;gap:.6rem;align-items:flex-end;">
            <div>
                <label class="hris-filter-label" for="search">Search</label>
                <input type="text" name="search" id="search" value="{{ $search }}" placeholder="Name or employee no."
                       class="hris-filter-select" style="min-width:14rem;">
            </div>
            <div>
                <label class="hris-filter-label" for="dept_id">Department</label>
                <select name="dept_id" id="dept_id" class="hris-filter-select" onchange="this.form.submit()">
                    <option value="">All departments</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->Dept_id }}" @selected($deptId === (int) $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="hris-filter-label" for="shift_id">Shift</label>
                <select name="shift_id" id="shift_id" class="hris-filter-select" onchange="this.form.submit()">
                    <option value="">All shifts</option>
                    @foreach ($shifts as $s)
                        <option value="{{ $s->id }}" @selected($shiftId === (int) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit" class="hris-btn hris-btn-primary">Search</button>
            </div>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table class="hris-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Assigned Shift</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $emp)
                    @php $fid = 'sched-form-'.$emp->id; @endphp
                    <tr>
                        <td style="font-weight:500;">{{ trim($emp->last_name.', '.$emp->first_name) }}</td>
                        <td>
                            <select form="{{ $fid }}" name="shift_id" class="sched-shift-select">
                                <option value="">Standard Day (default)</option>
                                @foreach ($shifts as $s)
                                    <option value="{{ $s->id }}" @selected((int) $emp->shift_id === (int) $s->id)>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <form id="{{ $fid }}" method="POST" action="{{ route('attendance.schedules.update', $emp) }}">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="hris-btn hris-btn-primary sched-save-btn">Save</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:1.5rem;">No employees found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination-wrap" style="padding:.75rem 1.25rem;">
        {{ $employees->links() }}
    </div>
</div>
@endsection

@section('page_scripts')
<script>
@if (session('schedule_status'))
    Swal.fire({ icon: 'success', title: 'Saved', text: @json(session('schedule_status')), confirmButtonColor: '#3b82f6' });
@endif
@if ($errors->any())
    Swal.fire({ icon: 'error', title: 'Could not save', text: @json($errors->first()), confirmButtonColor: '#3b82f6' });
@endif
</script>
@endsection
