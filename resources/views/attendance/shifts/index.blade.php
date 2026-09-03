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
.shift-inline-form { display:inline; }
.shift-badge-shared { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#dbeafe; color:#1e40af; }
.shift-badge-dept { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#e5e7eb; color:#374151; white-space:nowrap; }
.shift-scope-cell { min-width:16rem; }
.shift-scope-badges { display:flex; flex-wrap:wrap; gap:.3rem; margin-bottom:.4rem; }
.shift-dept-checklist { width:100%; min-width:14rem; max-height:9rem; overflow-y:auto; border:1px solid #cbd5e1; border-radius:.35rem; padding:.4rem .6rem; background:#fff; }
.shift-dept-checklist label { display:flex; align-items:center; gap:.4rem; font-size:.78rem; color:#374151; padding:.15rem 0; cursor:pointer; }
.shift-dept-checklist input { width:auto; }
.shift-badge-fieldwork { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#dcfce7; color:#166534; }
.shift-badge-singlepunch { display:inline-block; padding:.15rem .5rem; border-radius:9999px; font-size:.7rem; font-weight:600; background:#fef3c7; color:#92400e; }
.shift-cell-na { color:#94a3b8; font-size:.78rem; }
.fw-pattern-card { border-color:#bbf7d0; background:#f0fdf4; }
.fw-pattern-rule { margin-top:1rem; padding-top:.85rem; border-top:1px dashed #bbf7d0; }
.fw-pattern-rule h4 { margin:0 0 .5rem; font-size:.82rem; font-weight:600; color:#166534; }
.fw-pattern-table { width:100%; border-collapse:collapse; font-size:.76rem; }
.fw-pattern-table th, .fw-pattern-table td { padding:.35rem .5rem; border:1px solid #bbf7d0; text-align:left; }
.fw-pattern-table th { background:#dcfce7; color:#166534; font-weight:600; }
.fw-pattern-table td { color:#374151; background:#fff; }
.sp-pattern-card { border-color:#fde68a; background:#fffbeb; }
.sp-pattern-rule { margin-top:1rem; padding-top:.85rem; border-top:1px dashed #fde68a; }
.sp-pattern-rule h4 { margin:0 0 .5rem; font-size:.82rem; font-weight:600; color:#92400e; }
.sp-pattern-rule ul { margin:0; padding-left:1.1rem; font-size:.78rem; color:#374151; }
.sp-pattern-rule li { margin-bottom:.3rem; }
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
    <p style="margin:0 0 .75rem;font-size:.78rem;color:#64748b;">
        A template is just clock times, plus a default No Break (2-punch) setting used only to pre-fill the
        checkbox when you pick this template on the <a href="{{ route('attendance.schedules') }}">Shift
        Assignment</a> / Shift Schedule screens - Work Days, and the No Break actually used, are still set per
        employee there, so the same template can be scheduled differently (with or without a break, on different
        days) for different employees.
    </p>
    <form method="POST" action="{{ route('attendance.shifts.store') }}" id="create-shift-form">
        @csrf
        <div class="shift-form-grid">
            <div class="shift-field"><label>Name</label><input type="text" name="name" placeholder="e.g. Night" required></div>
            <div class="shift-field"><label>Time In</label><input type="time" name="time_in" value="08:00" required></div>
            <div class="shift-field"><label>Break Out</label><input type="time" name="break_out" value="12:00" required></div>
            <div class="shift-field"><label>Break In</label><input type="time" name="break_in" value="13:00" required></div>
            <div class="shift-field"><label>Time Out</label><input type="time" name="time_out" value="17:00" required></div>
            <div class="shift-field" style="align-self:center;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="no_break" value="1" id="create-no-break" style="width:auto;">
                    <span>No Break (2-punch) default</span>
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
            <div class="shift-field"><button type="submit" class="hris-btn hris-btn-primary">Add Shift</button></div>
        </div>
    </form>
</div>

{{-- Dedicated, minimal creation form for the Field Work weekly pattern -
     deliberately separate from the generic form above rather than a checkbox
     on it, since this shift type has no Break Out/In, No Break, or Punch
     Requirement to set: every day is a single in-only or out-only punch, and
     assigning it to an employee (Shift Assignment page) is fully automatic -
     no Work Days or per-day configuration needed there either. --}}
<div class="shift-form-card fw-pattern-card">
    <h3 style="margin:0 0 .5rem;font-size:.9rem;font-weight:600;color:#0f172a;">New Field Work Shift</h3>
    <p style="margin:0 0 .75rem;font-size:.78rem;color:#64748b;">
        A self-contained Monday check-in / Friday check-out weekly pattern for field-work employees. Time In
        is the Monday check-in anchor, Time Out is the Friday check-out anchor - nothing else to configure
        here or when assigning it. See the rule below.
    </p>
    <form method="POST" action="{{ route('attendance.shifts.store') }}" id="create-field-work-form">
        @csrf
        <input type="hidden" name="is_field_work_pair" value="1">
        <div class="shift-form-grid">
            <div class="shift-field"><label>Name</label><input type="text" name="name" placeholder="e.g. Field Work" required></div>
            <div class="shift-field"><label>Time In</label><input type="time" name="time_in" value="08:00" required></div>
            <div class="shift-field"><label>Time Out</label><input type="time" name="time_out" value="17:00" required></div>
            <div class="shift-field" style="align-self:center;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="is_global" value="1" id="create-fw-is-global" style="width:auto;" checked onchange="toggleCreateFwDeptPicker(this)">
                    <span>Shared / All Departments</span>
                </label>
            </div>
            <div class="shift-field" id="create-fw-dept-field" style="display:none;">
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
            <div class="shift-field"><button type="submit" class="hris-btn hris-btn-primary">Add Shift</button></div>
        </div>
    </form>
    <div class="fw-pattern-rule">
        <h4>Field Work Shift: Monday Check-In / Friday Check-Out Only</h4>
        <table class="fw-pattern-table">
            <thead>
                <tr><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Result</th></tr>
            </thead>
            <tbody>
                <tr><td>8:00 AM</td><td>–</td><td>–</td><td>–</td><td>5:00 PM</td><td>Mon Present, Tue–Thu excluded, Fri Present (normal week)</td></tr>
                <tr><td>–</td><td>–</td><td>–</td><td>–</td><td>5:00 PM</td><td>Mon–Thu all Absent (real consequences), Fri Present</td></tr>
                <tr><td>8:00 AM</td><td>–</td><td>–</td><td>–</td><td>–</td><td>Mon Absent (voided despite the punch), Tue–Thu Absent, Fri Absent</td></tr>
                <tr><td>–</td><td>–</td><td>9:15 AM</td><td>–</td><td>5:00 PM</td><td>Mon–Tue Absent, Wed Present/Late (check-in day), Thu excluded, Fri Present</td></tr>
                <tr><td>–</td><td>–</td><td>9:15 AM</td><td>–</td><td>–</td><td>Mon–Thu <b>all</b> Absent (Wed's punch is voided too &mdash; Friday missing wins), Fri Absent</td></tr>
            </tbody>
        </table>
    </div>
</div>

{{-- Single Punch Shift: a normal, full 4-slot daily schedule, but only AM In
     is ever graded. Keeps the same fields as the generic form above (unlike
     Field Work Shift's minimal form) since Break Out/In/Time Out are still
     real, useful reference times even though they're never penalized. --}}
<div class="shift-form-card sp-pattern-card">
    <h3 style="margin:0 0 .5rem;font-size:.9rem;font-weight:600;color:#0f172a;">New Single Punch Shift</h3>
    <p style="margin:0 0 .75rem;font-size:.78rem;color:#64748b;">
        For employees realistically expected to punch only AM In. AM Out/PM In/PM Out are still accepted and
        recorded if punched, but only AM In is graded for lateness against Time In below - see the rule below.
    </p>
    <form method="POST" action="{{ route('attendance.shifts.store') }}" id="create-single-punch-form">
        @csrf
        <input type="hidden" name="is_single_punch" value="1">
        <div class="shift-form-grid">
            <div class="shift-field"><label>Name</label><input type="text" name="name" placeholder="e.g. Single Punch" required></div>
            <div class="shift-field"><label>Time In</label><input type="time" name="time_in" value="08:00" required></div>
            <div class="shift-field"><label>Break Out</label><input type="time" name="break_out" value="12:00" required></div>
            <div class="shift-field"><label>Break In</label><input type="time" name="break_in" value="13:00" required></div>
            <div class="shift-field"><label>Time Out</label><input type="time" name="time_out" value="17:00" required></div>
            <div class="shift-field" style="align-self:center;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="no_break" value="1" id="create-sp-no-break" style="width:auto;">
                    <span>No Break (2-punch) default</span>
                </label>
            </div>
            <div class="shift-field" style="align-self:center;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="is_global" value="1" id="create-sp-is-global" style="width:auto;" checked onchange="toggleCreateSpDeptPicker(this)">
                    <span>Shared / All Departments</span>
                </label>
            </div>
            <div class="shift-field" id="create-sp-dept-field" style="display:none;">
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
            <div class="shift-field"><button type="submit" class="hris-btn hris-btn-primary">Add Shift</button></div>
        </div>
    </form>
    <div class="sp-pattern-rule">
        <h4>Single Punch Shift: Only AM In Is Graded</h4>
        <ul>
            <li>Late is checked only on AM In, against Time In above - a late lunch return (PM In) is never counted.</li>
            <li>Undertime is never charged, regardless of which slots are punched or missing.</li>
            <li>A day with <b>no punches at all</b> is Absent, same as any other shift type.</li>
            <li>A day where AM In specifically is missing but <b>another slot was punched</b> is treated as Late,
                using that punch's own time instead of AM In - not Absent, since it's still proof the employee
                was present.</li>
        </ul>
    </div>
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
                        @if ($shift->is_field_work_pair)
                            <td><span class="shift-cell-na">&mdash;</span></td>
                            <td><span class="shift-cell-na">&mdash;</span></td>
                        @else
                            <td><input form="{{ $fid }}" type="time" name="break_out" value="{{ $shift->break_out ? substr($shift->break_out,0,5) : '' }}" {{ $canManage ? '' : 'disabled' }}></td>
                            <td><input form="{{ $fid }}" type="time" name="break_in" value="{{ $shift->break_in ? substr($shift->break_in,0,5) : '' }}" {{ $canManage ? '' : 'disabled' }}></td>
                        @endif
                        <td><input form="{{ $fid }}" type="time" name="time_out" value="{{ substr($shift->time_out,0,5) }}" {{ $canManage ? '' : 'disabled' }}></td>
                        @if ($shift->is_field_work_pair)
                            <td style="text-align:center;"><span class="shift-cell-na">&mdash;</span></td>
                        @else
                            <td style="text-align:center;">
                                <input form="{{ $fid }}" type="checkbox" name="no_break" value="1"
                                    {{ $shift->no_break ? 'checked' : '' }} {{ $canManage ? '' : 'disabled' }}
                                    style="width:auto;cursor:pointer;">
                            </td>
                        @endif
                        <td>
                            @if ($shift->is_field_work_pair)
                                <span class="shift-badge-fieldwork">Field Work</span>
                            @elseif ($shift->is_single_punch)
                                <span class="shift-badge-singlepunch">Single Punch</span>
                            @elseif ($shift->crosses_midnight)
                                <span class="shift-badge-night">Night</span>
                            @else
                                <span class="shift-badge-day">Day</span>
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
                                    <input type="hidden" name="is_field_work_pair" value="{{ $shift->is_field_work_pair ? 1 : 0 }}">
                                    <input type="hidden" name="is_single_punch" value="{{ $shift->is_single_punch ? 1 : 0 }}">
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
                    <tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:1.5rem;">No shift templates yet. Add one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('page_scripts')
<script>
function toggleCreateDeptPicker(cb) {
    document.getElementById('create-dept-field').style.display = cb.checked ? 'none' : 'block';
}

function toggleCreateFwDeptPicker(cb) {
    document.getElementById('create-fw-dept-field').style.display = cb.checked ? 'none' : 'block';
}

function toggleCreateSpDeptPicker(cb) {
    document.getElementById('create-sp-dept-field').style.display = cb.checked ? 'none' : 'block';
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
