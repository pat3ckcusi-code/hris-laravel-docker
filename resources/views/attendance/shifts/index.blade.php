@extends('dashboards.layout', [
    'title'    => 'Shift Templates',
    'subtitle' => 'Define reusable work shifts. A shift that ends at or before it starts is treated as a night shift (crosses midnight).',
])

@section('page_head')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.shift-form-card { padding: 1rem 1.25rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .5rem; margin-bottom: 1rem; }
.shift-form-grid { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; }
.shift-field label { display: block; font-size: .72rem; color: #475569; margin-bottom: .2rem; }
.shift-field input { padding: .35rem .5rem; border: 1px solid #cbd5e1; border-radius: .35rem; font-size: .8rem; }
.shift-field input[type="time"] { width: 7rem; }
.shift-badge-night { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#e0e7ff; color:#3730a3; }
.shift-badge-day { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#e5e7eb; color:#374151; }
.shift-badge-nobreak { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#d1fae5; color:#065f46; }
.shift-inline-form { display:inline; }
</style>
@endsection

@section('content')

<div style="margin:0 0 1rem;">
    <a href="{{ route('attendance.schedules') }}" class="hris-btn">← Back to Shift Assignment</a>
</div>

{{-- Create new shift --}}
<div class="shift-form-card">
    <h3 style="margin:0 0 .75rem;font-size:.9rem;font-weight:600;color:#0f172a;">New Shift Template</h3>
    <form method="POST" action="{{ route('attendance.shifts.store') }}" id="create-shift-form">
        @csrf
        <div class="shift-form-grid">
            <div class="shift-field"><label>Name</label><input type="text" name="name" placeholder="e.g. Night" required></div>
            <div class="shift-field"><label>Time In</label><input type="time" name="time_in" value="08:00" required></div>
            <div class="shift-field create-break-field"><label>Break Out</label><input type="time" name="break_out" value="12:00" required></div>
            <div class="shift-field create-break-field"><label>Break In</label><input type="time" name="break_in" value="13:00" required></div>
            <div class="shift-field"><label>Time Out</label><input type="time" name="time_out" value="17:00" required></div>
            <div class="shift-field" style="align-self:center;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="no_break" value="1" id="create-no-break" style="width:auto;" onchange="toggleCreateBreak(this)">
                    <span>No Break (2-punch)</span>
                </label>
            </div>
            <div class="shift-field"><button type="submit" class="hris-btn hris-btn-primary">Add Shift</button></div>
        </div>
    </form>
</div>

<div class="hris-table-card">
    <div style="overflow-x:auto;">
        <table class="hris-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Time In</th>
                    <th>Break Out</th>
                    <th>Break In</th>
                    <th>Time Out</th>
                    <th>No Break</th>
                    <th>Type</th>
                    <th>Employees</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shifts as $shift)
                    @php $fid = 'shift-form-'.$shift->id; @endphp
                    <tr>
                        <td><input form="{{ $fid }}" type="text" name="name" value="{{ $shift->name }}" style="padding:.3rem .45rem;border:1px solid #cbd5e1;border-radius:.35rem;font-size:.8rem;"></td>
                        <td><input form="{{ $fid }}" type="time" name="time_in" value="{{ substr($shift->time_in,0,5) }}"></td>
                        <td class="row-break-field-{{ $shift->id }}"><input form="{{ $fid }}" type="time" name="break_out" value="{{ $shift->break_out ? substr($shift->break_out,0,5) : '' }}" {{ $shift->no_break ? 'disabled' : '' }}></td>
                        <td class="row-break-field-{{ $shift->id }}"><input form="{{ $fid }}" type="time" name="break_in" value="{{ $shift->break_in ? substr($shift->break_in,0,5) : '' }}" {{ $shift->no_break ? 'disabled' : '' }}></td>
                        <td><input form="{{ $fid }}" type="time" name="time_out" value="{{ substr($shift->time_out,0,5) }}"></td>
                        <td style="text-align:center;">
                            <input form="{{ $fid }}" type="checkbox" name="no_break" value="1"
                                {{ $shift->no_break ? 'checked' : '' }}
                                onchange="toggleRowBreak(this, {{ $shift->id }})"
                                style="width:auto;cursor:pointer;">
                        </td>
                        <td>
                            @if ($shift->no_break)
                                <span class="shift-badge-nobreak">2-Punch</span>
                            @elseif ($shift->crosses_midnight)
                                <span class="shift-badge-night">Night</span>
                            @else
                                <span class="shift-badge-day">Day</span>
                            @endif
                        </td>
                        <td style="text-align:center;">{{ $shift->employees_count }}</td>
                        <td style="white-space:nowrap;">
                            <form id="{{ $fid }}" class="shift-inline-form" method="POST" action="{{ route('attendance.shifts.update', $shift) }}">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="hris-btn hris-btn-primary">Save</button>
                            </form>
                            <form class="shift-inline-form" method="POST" action="{{ route('attendance.shifts.destroy', $shift) }}"
                                  onsubmit="return confirm('Delete this shift template?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="hris-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:1.5rem;">No shift templates yet. Add one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('page_scripts')
<script>
function toggleCreateBreak(cb) {
    const fields = document.querySelectorAll('.create-break-field input');
    fields.forEach(function(input) {
        input.disabled = cb.checked;
        input.required = !cb.checked;
    });
}

function toggleRowBreak(cb, shiftId) {
    const cells = document.querySelectorAll('.row-break-field-' + shiftId + ' input');
    cells.forEach(function(input) {
        input.disabled = cb.checked;
    });
}

@if (session('shift_status'))
    Swal.fire({ icon: 'success', title: 'Done', text: @json(session('shift_status')), confirmButtonColor: '#3b82f6' });
@endif
@if (session('shift_error'))
    Swal.fire({ icon: 'error', title: 'Action blocked', text: @json(session('shift_error')), confirmButtonColor: '#3b82f6' });
@endif
@if ($errors->any())
    Swal.fire({ icon: 'error', title: 'Invalid shift', text: @json($errors->first()), confirmButtonColor: '#3b82f6' });
@endif
</script>
@endsection
