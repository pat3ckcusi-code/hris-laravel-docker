@if($unmatchedDtrs->isEmpty())
    <div class="hris-empty-state">
        <div class="hris-empty-state-icon"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i></div>
        <div class="hris-empty-state-title">No unresolved punches found</div>
        <div class="hris-empty-state-text">Nothing in this range is stranded outside a time slot.</div>
    </div>
@else
    <div class="hris-table-wrapper">
        <table class="hris-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Unmatched Punch(es)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($unmatchedDtrs as $row)
                    @php
                        $emp = $row->employee;
                        $empName = $emp ? trim("{$emp->last_name}, {$emp->first_name}") : "Employee #{$row->employee_id}";
                        $statusRaw = $row->status ?? 'present';
                        $statusLabel = ucwords(str_replace('_', ' ', $statusRaw));
                        $statusBadgeClass = $statusRaw === 'present' ? 'badge-default' : 'badge-pending';
                    @endphp
                    <tr>
                        <td>{{ $empName }} <span class="text-muted">({{ $emp->EmpNo ?? '—' }})</span></td>
                        <td>{{ $emp?->department?->Dept_name ?? '—' }}</td>
                        <td>{{ $row->date->format('M j, Y') }}</td>
                        <td><span class="hris-badge {{ $statusBadgeClass }}">{{ $statusLabel }}</span></td>
                        <td>
                            @foreach($row->unmatched_logs as $t)
                                <span class="import-punch-chip">{{ substr($t, 0, 5) }}</span>
                            @endforeach
                        </td>
                        <td>
                            <form method="POST" action="{{ route('hr-manager.attendance.import.recompute-unmatched') }}"
                                  class="import-recompute-form"
                                  data-employee="{{ $empName }}" data-date="{{ $row->date->format('M j, Y') }}">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $row->employee_id }}">
                                <input type="hidden" name="date" value="{{ $row->date->toDateString() }}">
                                <button type="submit" class="hris-btn-secondary hris-btn-sm">
                                    <i class="fa-solid fa-arrows-rotate"></i> Recompute
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <x-hris.table-pagination :paginator="$paginator" />

    @if($totalMatchingCount > $candidatesFetchedCount)
        <div class="hris-table-footer">
            <p style="margin:0;font-size:0.78rem;color:#94a3b8;">
                Only the {{ $candidatesFetchedCount }} most recent of {{ $totalMatchingCount }} matching rows in this range were checked — narrow the date range or department to see the rest.
            </p>
        </div>
    @endif
@endif
