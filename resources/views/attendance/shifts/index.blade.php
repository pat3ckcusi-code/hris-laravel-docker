@extends('dashboards.layout', [
    'title'    => 'Shift Templates',
    'subtitle' => 'Define reusable work shifts. A shift that ends at or before it starts is treated as a night shift (crosses midnight).',
])

@section('page_head')
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
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
.shift-badge-shared { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#dbeafe; color:#1e40af; }
.shift-badge-dept { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#e5e7eb; color:#374151; white-space:nowrap; }
.shift-scope-cell { min-width:16rem; }
.shift-scope-badges { display:flex; flex-wrap:wrap; gap:.3rem; margin-bottom:.4rem; }
.shift-dept-checklist { width:100%; min-width:14rem; max-height:9rem; overflow-y:auto; border:1px solid #cbd5e1; border-radius:.35rem; padding:.4rem .6rem; background:#fff; }
.shift-dept-checklist label { display:flex; align-items:center; gap:.4rem; font-size:.78rem; color:#374151; padding:.15rem 0; cursor:pointer; }
.shift-dept-checklist input { width:auto; }
.shift-days-picker { display:flex; flex-wrap:wrap; gap:.5rem; }
.shift-days-picker label { display:flex; align-items:center; gap:.25rem; font-size:.75rem; color:#374151; cursor:pointer; }
.shift-days-picker input { width:auto; }
</style>
@endsection

@section('content')

<div style="margin:0 0 1rem;">
    <a href="{{ route('attendance.schedules') }}" class="hris-btn">← Back to Shift Assignment</a>
</div>

{{-- Create new shift --}}
@if ($canManage)
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
            <div class="shift-field" style="align-self:center;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="is_global" value="1" id="create-is-global" style="width:auto;" checked onchange="toggleCreateDeptPicker(this)">
                    <span>Shared / All Departments</span>
                </label>
            </div>
            <div class="shift-field" id="create-dept-field" style="display:none;">
                <label>Departments</label>
                <div class="shift-dept-checklist">
                    @foreach ($departments as $d)
                        <label>
                            <input type="checkbox" name="department_ids[]" value="{{ $d->Dept_id }}">
                            <span>{{ $d->Dept_name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="shift-field">
                <label>Work Days</label>
                <div class="shift-days-picker">
                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $val => $label)
                        <label>
                            <input type="checkbox" name="work_days[]" value="{{ $val }}" {{ in_array($val, [1, 2, 3, 4, 5]) ? 'checked' : '' }}>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="shift-field"><button type="submit" class="hris-btn hris-btn-primary">Add Shift</button></div>
        </div>
    </form>
</div>
@else
<div class="shift-form-card">
    <span style="color:#64748b;font-size:.82rem;">View only - your Time Keeper manages shift templates.</span>
</div>
@endif

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
                    <th>Work Days</th>
                    <th class="shift-scope-cell">Scope</th>
                    <th>Employees</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shifts as $shift)
                    @php $fid = 'shift-form-'.$shift->id; @endphp
                    <tr>
                        <td><input form="{{ $fid }}" type="text" name="name" value="{{ $shift->name }}" style="padding:.3rem .45rem;border:1px solid #cbd5e1;border-radius:.35rem;font-size:.8rem;" {{ $canManage ? '' : 'disabled' }}></td>
                        <td><input form="{{ $fid }}" type="time" name="time_in" value="{{ substr($shift->time_in,0,5) }}" {{ $canManage ? '' : 'disabled' }}></td>
                        <td class="row-break-field-{{ $shift->id }}"><input form="{{ $fid }}" type="time" name="break_out" value="{{ $shift->break_out ? substr($shift->break_out,0,5) : '' }}" {{ ($shift->no_break || ! $canManage) ? 'disabled' : '' }}></td>
                        <td class="row-break-field-{{ $shift->id }}"><input form="{{ $fid }}" type="time" name="break_in" value="{{ $shift->break_in ? substr($shift->break_in,0,5) : '' }}" {{ ($shift->no_break || ! $canManage) ? 'disabled' : '' }}></td>
                        <td><input form="{{ $fid }}" type="time" name="time_out" value="{{ substr($shift->time_out,0,5) }}" {{ $canManage ? '' : 'disabled' }}></td>
                        <td style="text-align:center;">
                            <input form="{{ $fid }}" type="checkbox" name="no_break" value="1"
                                {{ $shift->no_break ? 'checked' : '' }}
                                onchange="toggleRowBreak(this, {{ $shift->id }})"
                                style="width:auto;cursor:pointer;" {{ $canManage ? '' : 'disabled' }}>
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
                        <td>
                            <div style="font-size:.78rem;color:#374151;margin-bottom:.35rem;">{{ $shift->workDaysLabel() }}</div>
                            @if ($canManage)
                                <div class="shift-days-picker">
                                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $val => $label)
                                        <label>
                                            <input form="{{ $fid }}" type="checkbox" name="work_days[]" value="{{ $val }}" {{ in_array($val, $shift->work_days ?: [1, 2, 3, 4, 5]) ? 'checked' : '' }}>
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="shift-scope-cell">
                            <div class="shift-scope-badges">
                                @if ($shift->is_global)
                                    <span class="shift-badge-shared">Shared / All Departments</span>
                                @elseif ($shift->departments->isEmpty())
                                    <span class="shift-badge-dept" style="color:#b91c1c;background:#fee2e2;">No departments assigned</span>
                                @else
                                    @foreach ($shift->departments as $dept)
                                        <span class="shift-badge-dept">{{ $dept->Dept_name }}</span>
                                    @endforeach
                                @endif
                            </div>
                            @if ($canManage)
                                <label style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;color:#475569;margin-bottom:.35rem;cursor:pointer;">
                                    <input form="{{ $fid }}" type="checkbox" name="is_global" value="1"
                                        {{ $shift->is_global ? 'checked' : '' }}
                                        onchange="toggleRowDeptPicker(this, {{ $shift->id }})"
                                        style="width:auto;cursor:pointer;">
                                    <span>Shared / All Departments</span>
                                </label>
                                <div id="dept-field-{{ $shift->id }}" style="{{ $shift->is_global ? 'display:none;' : '' }}">
                                    <div class="shift-dept-checklist">
                                        @foreach ($departments as $d)
                                            <label>
                                                <input form="{{ $fid }}" type="checkbox" name="department_ids[]" value="{{ $d->Dept_id }}" {{ $shift->departments->contains('Dept_id', $d->Dept_id) ? 'checked' : '' }}>
                                                <span>{{ $d->Dept_name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </td>
                        <td style="text-align:center;">{{ $shift->employees_count }}</td>
                        <td style="white-space:nowrap;">
                            @if ($canManage)
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
                            @else
                                <span style="color:#94a3b8;font-size:.75rem;">View only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" style="text-align:center;color:#94a3b8;padding:1.5rem;">No shift templates yet. Add one above.</td></tr>
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

function toggleCreateDeptPicker(cb) {
    document.getElementById('create-dept-field').style.display = cb.checked ? 'none' : 'block';
}

function toggleRowDeptPicker(cb, shiftId) {
    document.getElementById('dept-field-' + shiftId).style.display = cb.checked ? 'none' : 'block';
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
