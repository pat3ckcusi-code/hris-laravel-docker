@extends('dashboards.layout', [
    'title' => 'Payroll Runs',
    'subtitle' => 'Manage and monitor all payroll cycles.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('createRunModal').showModal()"><i class="fas fa-plus"></i> New Payroll Run</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <div class="plantilla-stats">
        <div class="stat-tile">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-label">Total Runs</div>
            </div>
        </div>
        <div class="stat-tile stat-vacant">
            <div class="stat-icon"><i class="fas fa-pen"></i></div>
            <div>
                <div class="stat-value">{{ $stats['draft'] }}</div>
                <div class="stat-label">Draft</div>
            </div>
        </div>
        <div class="stat-tile stat-info">
            <div class="stat-icon"><i class="fas fa-calculator"></i></div>
            <div>
                <div class="stat-value">{{ $stats['computed'] }}</div>
                <div class="stat-label">Computed</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-lock"></i></div>
            <div>
                <div class="stat-value">{{ $stats['locked'] }}</div>
                <div class="stat-label">Locked</div>
            </div>
        </div>
    </div>

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$runs">
        <table class="hris-table" id="payroll-runs-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Period</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Locked At</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($runs as $run)
                    <tr>
                        <td><span class="item-badge">#{{ $run->id }}</span></td>
                        <td><i class="fas fa-calendar-days" style="color:var(--accent);margin-right:8px"></i><strong>{{ $run->period }}</strong></td>
                        <td>{{ $run->period_start ? $run->period_start->format('M d, Y') : '-' }}</td>
                        <td>{{ $run->period_end ? $run->period_end->format('M d, Y') : '-' }}</td>
                        <td><span class="status-chip status-{{ $run->status }}">{{ ucfirst($run->status) }}</span></td>
                        <td>{{ $run->creator->name ?? '-' }}</td>
                        <td>
                            @if($run->locked_at)
                                <i class="fas fa-lock" style="color:#3730a3;margin-right:6px"></i>{{ $run->locked_at->format('M d, Y H:i') }}
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>{{ $run->created_at->format('M d, Y') }}</td>
                        <td>
                            <div class="action-btns">
                                <a href="{{ route('payroll.runs.show', $run->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm">View</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
<dialog id="createRunModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0"><i class="fas fa-money-bill-wave" style="color:var(--accent);margin-right:8px"></i>New Payroll Run</h3>
            <span class="record-email">Start a new payroll cycle</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.runs.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="period"><i class="fas fa-tag"></i> Pay Period</label>
            <input type="text" id="period" name="period" value="{{ old('period') }}" placeholder="e.g. April 1-15, 2026" required class="form-input">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="period_start"><i class="fas fa-calendar-check"></i> Period Start</label>
                <input type="date" id="period_start" name="period_start" value="{{ old('period_start') }}" required class="form-input">
            </div>
            <div class="form-group">
                <label for="period_end"><i class="fas fa-calendar-check"></i> Period End</label>
                <input type="date" id="period_end" name="period_end" value="{{ old('period_end') }}" min="{{ old('period_start') }}" required class="form-input">
                <div class="text-danger" id="period-end-error" style="font-size:0.85rem;margin-top:4px;display:none">Period End cannot be before Period Start.</div>
                @error('period_end') <div class="text-danger" style="font-size:0.85rem;margin-top:4px">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-group">
            <label><i class="fas fa-users"></i> Employee Types to Include</label>
            @foreach($employeeTypes as $type)
                <label class="checkbox-label" style="display:flex;margin-bottom:8px;font-weight:700">
                    <input type="checkbox" name="employee_types[]" value="{{ $type }}"
                           @checked(old('employee_types') === null || in_array($type, old('employee_types', []), true))>
                    {{ $type }}
                </label>
            @endforeach
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-plus"></i> Create Payroll Run</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
    $(function () {
        $('#payroll-runs-table').DataTable({ paging: false, info: false });
    });
    @if ($errors->any())
        document.getElementById('createRunModal').showModal();
    @endif

    (function () {
        var periodStart = document.getElementById('period_start');
        var periodEnd = document.getElementById('period_end');
        var periodEndError = document.getElementById('period-end-error');
        var createRunForm = periodEnd ? periodEnd.closest('form') : null;

        function syncMinAndValidate() {
            if (!periodStart.value) { return; }
            periodEnd.min = periodStart.value;
            if (periodEnd.value && periodEnd.value < periodStart.value) {
                periodEndError.style.display = 'block';
            } else {
                periodEndError.style.display = 'none';
            }
        }

        if (periodStart && periodEnd) {
            periodStart.addEventListener('change', syncMinAndValidate);
            periodEnd.addEventListener('change', syncMinAndValidate);
        }

        if (createRunForm) {
            createRunForm.addEventListener('submit', function (e) {
                if (periodStart.value && periodEnd.value && periodEnd.value < periodStart.value) {
                    e.preventDefault();
                    periodEndError.style.display = 'block';
                    periodEnd.focus();
                }
            });
        }
    })();
</script>
@endsection
