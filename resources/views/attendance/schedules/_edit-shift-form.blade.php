{{--
    Pre-filled correction form for one ShiftAssignment row ($row), shared by
    the current-shifts list and the History (expired) list on the Shift
    Assignment screen. Submitting with $row's own effective_from unchanged
    triggers ShiftAssignmentService::assign()'s same-start-date replacement
    rule, so this edits the row in place rather than appending new history.

    Expects: $emp (User), $empName (string), $row (ShiftAssignment), $shifts (Collection<Shift>)
--}}
@php
    $rowShiftLabel = $row->shift?->name ?? 'Standard Day';
    $rowDaysLabel = $row->workDaysLabel();
    $rowDateLabel = match (true) {
        $row->isSuperseded() => 'superseded before it took effect',
        $row->effective_until !== null => $row->effective_from->toFormattedDateString().' – '.$row->effective_until->toFormattedDateString(),
        default => 'from '.$row->effective_from->toFormattedDateString(),
    };
    // A shift template can be deactivated after a row was assigned it - keep
    // it selectable (flagged inactive) rather than silently swapping the
    // pre-selected value to "Standard Day" on a form meant to preserve data.
    $rowShiftInList = $row->shift_id === null || $shifts->contains('id', $row->shift_id);
@endphp
<details class="sched-edit-shift">
    <summary>Edit</summary>
    <form method="POST" action="{{ route('attendance.schedules.update', $emp) }}"
          class="sched-edit-shift-form" data-name="{{ $empName }}"
          data-shift="{{ $rowShiftLabel }}" data-dates="{{ $rowDateLabel }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="form_type" value="edit">
        <select name="shift_id" class="sched-shift-select">
            <option value="" @selected($row->shift_id === null)>Standard Day</option>
            @unless($rowShiftInList)
                <option value="{{ $row->shift_id }}" selected>{{ $row->shift->name }} (inactive)</option>
            @endunless
            @foreach ($shifts as $s)
                <option value="{{ $s->id }}" @selected($row->shift_id === $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
        <label style="display:block;font-size:.72rem;color:#475569;margin-top:.3rem;">Work Days</label>
        <div class="sched-days-group sched-workdays-group">
            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow => $label)
                <label class="sched-day-chip">
                    <input type="checkbox" name="work_days[]" value="{{ $dow }}"
                           @checked(in_array($dow, $row->work_days ?: \App\Models\ShiftAssignment::DEFAULT_WORK_DAYS, true))> {{ $label }}
                </label>
            @endforeach
        </div>
        <label class="sched-day-chip">
            <input type="checkbox" name="no_break" value="1" @checked($row->no_break)> No Break (2-punch)
        </label>
        <details class="sched-advanced-split" @if ($row->days_of_week) open @endif>
            <summary>Advanced: split into concurrent shifts</summary>
            <div class="sched-days-group">
                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow => $label)
                    <label class="sched-day-chip">
                        <input type="checkbox" name="days_of_week[]" value="{{ $dow }}"
                               @checked($row->days_of_week && in_array($dow, $row->days_of_week, true))> {{ $label }}
                    </label>
                @endforeach
            </div>
            <p class="sched-days-hint">
                Only needed for a concurrent second shift on different days. While open, Work Days above
                follows this selection.
            </p>
        </details>
        <div class="sched-add-dates">
            <input type="date" name="effective_from" value="{{ $row->effective_from->toDateString() }}"
                   title="Effective from (required)" required>
            <input type="date" name="effective_until" value="{{ $row->effective_until?->toDateString() }}"
                   title="Effective until (required)" required>
        </div>
        <button type="submit" class="hris-btn hris-btn-primary sched-edit-submit">Save Correction</button>
    </form>
</details>
