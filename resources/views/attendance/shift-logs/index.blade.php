@extends('dashboards.layout', [
    'title' => 'Shift Logs',
    'subtitle' => 'A full log of every shift-related change, company-wide.',
])

@section('content')

{{-- Filter bar --}}
<div class="tile" style="margin-bottom:1rem;">
    <form method="GET" action="{{ route('attendance.shift-logs') }}"
          style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">

        <div style="display:flex;flex-direction:column;gap:0.3rem;">
            <label for="dept_id" style="font-size:0.82rem;font-weight:600;color:#374151;">Department</label>
            <select id="dept_id" name="dept_id"
                    style="padding:0.5rem 0.7rem;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;background:#fff;">
                <option value="">All Departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->Dept_id }}" @selected($deptId === (int) $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit"
                style="padding:0.52rem 1.1rem;background:#374151;color:#fff;border:none;border-radius:6px;
                       font-size:0.9rem;font-weight:600;cursor:pointer;">
            View
        </button>
    </form>
</div>

{{-- Shift Change Log: every shift-related action, company-wide --}}
@include('attendance.shift-logs._log_table', [
    'logs' => $logs,
    'title' => 'Shift Change Log',
    'subtitle' => 'Every shift template, assignment, schedule, and access change, most recent first.',
])

{{-- Drill-down for a collapsed bulk-action row ("N employees") --}}
<div class="modal-overlay" id="shiftLogBatchModal">
    <div class="modal-box" style="max-width:640px;">
        <button type="button" class="modal-close">&times;</button>
        <h3 id="shiftLogBatchModalTitle" style="margin-top:0;"></h3>
        <p id="shiftLogBatchModalSubtitle" style="color:#6b7280;font-size:0.85rem;"></p>
        <div class="hris-table-wrapper" style="max-height:420px;overflow-y:auto;">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                    </tr>
                </thead>
                <tbody id="shiftLogBatchModalBody"></tbody>
            </table>
        </div>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('shiftLogBatchModal');
    var modalBody = document.getElementById('shiftLogBatchModalBody');
    var modalTitle = document.getElementById('shiftLogBatchModalTitle');
    var modalSubtitle = document.getElementById('shiftLogBatchModalSubtitle');

    function closeModal() {
        modal.classList.remove('active');
    }

    function clearModalBody() {
        while (modalBody.firstChild) {
            modalBody.removeChild(modalBody.firstChild);
        }
    }

    function appendRow(cells) {
        var tr = document.createElement('tr');
        cells.forEach(function (text) {
            var td = document.createElement('td');
            td.textContent = text;
            tr.appendChild(td);
        });
        modalBody.appendChild(tr);
    }

    modal.querySelector('.modal-close').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    document.querySelectorAll('.shift-log-batch-trigger').forEach(function (el) {
        el.addEventListener('click', function () {
            var batchId = el.dataset.batchId;
            var deptId = new URLSearchParams(window.location.search).get('dept_id') || '';

            modalTitle.textContent = el.dataset.actionLabel || 'Affected Employees';
            modalSubtitle.textContent = 'Loading...';
            clearModalBody();
            modal.classList.add('active');

            var url = '{{ url('/attendance/shift-logs/batch') }}/' + encodeURIComponent(batchId) + '/employees';
            if (deptId) {
                url += '?dept_id=' + encodeURIComponent(deptId);
            }

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    modalSubtitle.textContent = data.count + ' employee(s) affected';
                    clearModalBody();

                    if (!data.employees.length) {
                        var tr = document.createElement('tr');
                        var td = document.createElement('td');
                        td.colSpan = 2;
                        td.className = 'hris-empty-state';
                        td.textContent = 'No employees found.';
                        tr.appendChild(td);
                        modalBody.appendChild(tr);
                        return;
                    }

                    data.employees.forEach(function (emp) {
                        appendRow([emp.name, emp.department]);
                    });
                })
                .catch(function () {
                    modalSubtitle.textContent = 'Failed to load employees.';
                });
        });
    });
});
</script>
@endsection
