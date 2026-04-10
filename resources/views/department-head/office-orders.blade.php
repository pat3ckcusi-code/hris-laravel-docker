@extends('dashboards.layout', [
    'title' => 'Office Orders',
    'subtitle' => 'Manage office orders and communications',
])

@section('tiles')
    <article class="tile">
        <strong>Office Orders</strong>
        List and manage office orders for your department.
    </article>
@endsection
@section('content')
<div class="card mb-4 shadow-lg border-0 rounded-lg pro-travel-card">
    <div class="card-body p-4">
        <form id="officeOrderForm" action="{{ route('api.office-orders') }}" method="POST" class="needs-validation" novalidate autocomplete="off">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <div class="mb-4">
                <label class="font-weight-bold mb-2">Select Employees <span class="text-muted" style="font-size:0.95em;">(choose one or more)</span></label>
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" id="selectAllEmployees" class="mr-2">
                    <label for="selectAllEmployees" class="mb-0">Select All</label>
                </div>
                <div id="employeesList" class="border rounded p-2 bg-light" style="max-height:220px; overflow:auto;">
                    <div class="text-muted">Loading employees...</div>
                </div>

                <div class="field-grid two" style="margin-top:10px;">
                    <label>
                        Date Issued
                        <input type="date" class="form-input" id="issued_date" name="issued_date" required>
                    </label>
                    <label>
                        Effective Until
                        <input type="date" class="form-input" id="effective_date" name="effective_date">
                    </label>
                </div>

            </div>

            <div class="field-grid">
                <label>
                    Subject / Title
                    <input type="text" class="form-input" id="subject" name="subject" required placeholder="Enter subject/title">
                </label>
            </div>

            <div class="field-grid">
                <label>
                    Details
                    <textarea class="form-input" id="details" name="details" rows="3" placeholder="Describe the office order" required></textarea>
                </label>
            </div>

            <div class="mb-4">
                <label class="w-100">
                    Remarks / Special Instructions
                    <textarea class="form-input" id="remarks" name="remarks" rows="2" placeholder="Any special instructions"></textarea>
                </label>
            </div>

            <div class="text-right">
                <button type="submit" class="btn btn-lg btn-primary px-5 shadow-sm" id="submitOfficeOrder" style="font-weight:600; letter-spacing:0.5px;">Submit Office Order</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dep = document.getElementById('issued_date');
    const arr = document.getElementById('effective_date');
    if (!dep || !arr) return;
    const today = new Date().toISOString().slice(0, 10);
    dep.setAttribute('min', today);
    if (!arr.getAttribute('min')) arr.setAttribute('min', today);
    function syncArrivalMin() {
        const depVal = dep.value || today;
        arr.setAttribute('min', depVal);
        if (arr.value && arr.value < depVal) {
            arr.value = '';
        }
    }
    dep.addEventListener('change', syncArrivalMin);
    syncArrivalMin();
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    fetch('/api/department-employees')
        .then(response => response.json())
        .then(data => {
            const employeesList = document.getElementById('employeesList');
            if (data.employees && data.employees.length > 0) {
                employeesList.innerHTML = data.employees.map(emp => `
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="employee_ids[]" value="${emp.id}" id="emp_${emp.id}">
                        <label class="form-check-label" for="emp_${emp.id}">
                            <span style="font-weight:500;">${emp.last_name}, ${emp.first_name}</span>
                            <span class="text-muted" style="font-size:0.85em; font-style:italic;">${emp.designation || ''}</span>
                        </label>
                    </div>
                `).join('');
            } else {
                employeesList.innerHTML = '<div class="text-muted">No employees found in your department.</div>';
            }
        })
        .catch(() => {
            document.getElementById('employeesList').innerHTML = '<div class="text-danger">Failed to load employees.</div>';
        });

    document.getElementById('selectAllEmployees').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('#employeesList input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
});
</script>
<script>
async function ensureSwal() {
    if (window.Swal && typeof Swal.fire === 'function') return window.Swal;
    return new Promise((resolve) => {
        const localPaths = [
            '/js/app.js'
        ];

        const tryLoad = (src) => new Promise((res) => {
            const s = document.createElement('script');
            s.src = src;
            s.onload = () => res(window.Swal || null);
            s.onerror = () => res(null);
            document.head.appendChild(s);
        });

        (async () => {
            for (const p of localPaths) {
                try {
                    const loaded = await tryLoad(p);
                    if (loaded) return resolve(window.Swal || null);
                } catch (e) {}
            }
            return resolve(null);
        })();
    });
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('officeOrderForm');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const SwalLib = await ensureSwal();
        const proceed = async () => {
            try {
                const action = form.getAttribute('action') || window.location.href;
                const method = (form.getAttribute('method') || 'POST').toUpperCase();
                const formData = new FormData(form);
                const tokenInput = form.querySelector('input[name="_token"]');
                const headers = { 'X-Requested-With': 'XMLHttpRequest' };
                if (tokenInput) headers['X-CSRF-TOKEN'] = tokenInput.value;

                const resp = await fetch(action, { method, body: formData, headers, credentials: 'same-origin' });
                const text = await resp.text();
                let data = null;
                try { data = JSON.parse(text); } catch (err) { data = null; }

                if (resp.status === 422 && data && data.errors) {
                    const msgs = [];
                    for (const k in data.errors) if (data.errors[k]) msgs.push(...data.errors[k]);
                    if (SwalLib) {
                        await SwalLib.fire({ icon: 'error', title: 'Validation error', text: msgs.join('\n') });
                    } else {
                        alert(msgs.join('\n'));
                    }
                    return;
                }

                if (resp.ok && data && (data.success || data.message)) {
                    if (SwalLib) {
                        await SwalLib.fire({ icon: 'success', title: 'Success', text: data.message || data.success });
                    } else alert(data.message || data.success || 'Success');
                    if (data.redirect) return window.location = data.redirect;
                    return window.location.reload();
                }

                if (resp.ok) {
                    if (SwalLib) await SwalLib.fire({ icon: 'success', title: 'Success', text: 'Office order submitted.' });
                    return window.location.reload();
                }

                if (SwalLib) await SwalLib.fire({ icon: 'error', title: 'Error', text: data && data.message ? data.message : 'Failed to submit office order.' });
                else alert(data && data.message ? data.message : 'Failed to submit office order.');
            } catch (err) {
                form.submit();
            }
        };

        if (SwalLib) {
            const result = await SwalLib.fire({
                title: 'Submit Office Order?',
                text: 'Are you sure you want to submit this office order?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, submit',
                cancelButtonText: 'Cancel'
            });
            if (result.isConfirmed) await proceed();
        } else {
            if (confirm('Submit Office Order?')) await proceed();
        }
    });
});
</script>
@endsection
