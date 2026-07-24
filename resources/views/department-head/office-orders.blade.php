@extends('dashboards.layout', [
    'title' => 'Office Orders',
    'subtitle' => 'Issue an internal memo to employees in your department.',
])

@section('page_head')
<style>
    #employeesList .form-check { transition: background-color 0.12s ease; border-radius: 6px; }
    #employeesList .form-check:hover { background: #fff7ed; }
    .oo-info-banner {
        display: flex; align-items: flex-start; gap: 10px;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.75rem;
        padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.875rem; color: #475569;
    }
    .oo-info-banner i { color: var(--accent, #ea580c); margin-top: 2px; }
    .oo-section-title {
        font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 0.6rem;
    }
</style>
@endsection

@section('content')
@php $isEdit = isset($order); @endphp
<div class="card mb-4 shadow-lg border-0 rounded-lg pro-travel-card">
    <div class="card-header d-flex" style="padding:1.1rem 1.5rem; justify-content:space-between; align-items:center;">
        <div>
            <h3 class="card-title" style="margin:0; display:flex; align-items:center; gap:0.5rem;">
                <i class="fas fa-file-signature"></i> {{ $isEdit ? 'Edit Office Order' : 'New Office Order' }}
            </h3>
            <div class="text-muted" style="font-size:0.85rem; margin-top:2px;">
                {{ $isEdit ? 'Office Order No. ' . $order->office_order_num . ' — number is preserved' : 'Number is auto-assigned (format: YYYY - NNN)' }}
            </div>
        </div>
        @if ($isEdit)
            <span class="badge badge-warning">Editing</span>
        @endif
    </div>
    <div class="card-body p-4">
        <form id="officeOrderForm"
              action="{{ $isEdit ? route('api.office-orders.update', $order->id) : route('api.office-orders') }}"
              method="POST" class="needs-validation" novalidate autocomplete="off">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            @if ($isEdit)<input type="hidden" name="_method" value="PUT">@endif

            <div class="oo-info-banner">
                <i class="fas fa-circle-info"></i>
                <div><strong>From:</strong> Department Head of the recipients' department (set automatically on the document).</div>
            </div>

            <div class="oo-section-title">Recipients</div>
            <div class="mb-4">
                <label class="font-weight-bold mb-2">To (Recipients) <span class="text-muted" style="font-size:0.95em;">(choose one or more)</span></label>
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" id="selectAllEmployees" class="mr-2">
                    <label for="selectAllEmployees" class="mb-0">Select All</label>
                </div>
                <div id="employeesList" class="border rounded p-2 bg-light" style="max-height:220px; overflow:auto;">
                    <div class="text-muted">Loading employees...</div>
                </div>

                <div class="field-grid two" style="margin-top:10px;">
                    <label>
                        Date
                        <input type="date" class="form-input" id="issued_date" name="issued_date" required value="{{ $isEdit ? \Illuminate\Support\Str::of($order->issued_date)->substr(0, 10) : '' }}">
                    </label>
                    <label>
                        Effective Until
                        <input type="date" class="form-input" id="effective_date" name="effective_date" value="{{ $isEdit && $order->effective_date ? \Illuminate\Support\Str::of($order->effective_date)->substr(0, 10) : '' }}">
                    </label>
                </div>

            </div>

            <div class="oo-section-title">Content</div>
            <div class="field-grid">
                <label>
                    Subject
                    <input type="text" class="form-input" id="subject" name="subject" required placeholder="Enter subject (e.g. As Stated)" value="{{ $isEdit ? $order->subject : '' }}">
                </label>
            </div>

            <div class="field-grid">
                <label>
                    Body / Directive
                    <textarea class="form-input" id="details" name="details" rows="4" placeholder="Write the directive/body of the office order" required>{{ $isEdit ? $order->details : '' }}</textarea>
                </label>
            </div>

            <div class="mb-4">
                <label class="w-100">
                    Remarks / Special Instructions
                    <textarea class="form-input" id="remarks" name="remarks" rows="2" placeholder="Any special instructions">{{ $isEdit ? $order->Remarks : '' }}</textarea>
                </label>
            </div>

            <div class="text-right">
                <button type="submit" class="btn btn-lg btn-primary px-5 shadow-sm" id="submitOfficeOrder" style="font-weight:600; letter-spacing:0.5px;">
                    <i class="fas {{ $isEdit ? 'fa-save' : 'fa-paper-plane' }}"></i> {{ $isEdit ? 'Update Office Order' : 'Submit Office Order' }}
                </button>
            </div>
        </form>
    </div>
</div>
<script>
    const OO_IS_EDIT = @json($isEdit);
    const OO_SELECTED_IDS = @json($selectedIds ?? []);
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dep = document.getElementById('issued_date');
    const arr = document.getElementById('effective_date');
    if (!dep || !arr) return;
    const today = new Date().toISOString().slice(0, 10);
    // Allow keeping/choosing past dates when editing an existing order.
    if (!OO_IS_EDIT) {
        dep.setAttribute('min', today);
        if (!arr.getAttribute('min')) arr.setAttribute('min', today);
    }
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
                        <input class="form-check-input" type="checkbox" name="employee_ids[]" value="${emp.id}" id="emp_${emp.id}" ${OO_SELECTED_IDS.includes(Number(emp.id)) ? 'checked' : ''}>
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
                title: OO_IS_EDIT ? 'Update Office Order?' : 'Submit Office Order?',
                text: OO_IS_EDIT ? 'Are you sure you want to save these changes?' : 'Are you sure you want to submit this office order?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: OO_IS_EDIT ? 'Yes, update' : 'Yes, submit',
                cancelButtonText: 'Cancel'
            });
            if (result.isConfirmed) await proceed();
        } else {
            if (confirm(OO_IS_EDIT ? 'Update Office Order?' : 'Submit Office Order?')) await proceed();
        }
    });
});
</script>
@endsection
