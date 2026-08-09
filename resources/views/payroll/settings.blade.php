@extends('dashboards.layout', [
    'title' => 'Payroll Settings',
    'subtitle' => 'Signatory names and designations printed on the General Payroll export.',
])

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <p class="text-muted" style="margin-bottom:20px">
        Leave a field blank to keep whatever name/designation is already printed in the Payroll Form template.
    </p>

    <form method="POST" action="{{ route('payroll.settings.update') }}" class="payroll-form" id="signatories-form">
        @csrf
        @method('PUT')

        <section class="payroll-section" style="margin-bottom:24px">
            <h2>Mayor</h2>
            <div class="grid">
                <div class="form-group">
                    <label for="payroll_signatory_mayor_name">Name</label>
                    <input type="text" name="payroll_signatory_mayor_name" id="payroll_signatory_mayor_name" class="form-input"
                           value="{{ old('payroll_signatory_mayor_name', $settings['payroll_signatory_mayor_name']->value ?? '') }}">
                </div>
                <div class="form-group">
                    <label for="payroll_signatory_mayor_designation">Designation</label>
                    <input type="text" name="payroll_signatory_mayor_designation" id="payroll_signatory_mayor_designation" class="form-input"
                           value="{{ old('payroll_signatory_mayor_designation', $settings['payroll_signatory_mayor_designation']->value ?? '') }}">
                </div>
            </div>
        </section>

        <section class="payroll-section" style="margin-bottom:24px">
            <h2>City Accountant</h2>
            <div class="grid">
                <div class="form-group">
                    <label for="payroll_signatory_accountant_name">Name</label>
                    <input type="text" name="payroll_signatory_accountant_name" id="payroll_signatory_accountant_name" class="form-input"
                           value="{{ old('payroll_signatory_accountant_name', $settings['payroll_signatory_accountant_name']->value ?? '') }}">
                </div>
                <div class="form-group">
                    <label for="payroll_signatory_accountant_designation">Designation</label>
                    <input type="text" name="payroll_signatory_accountant_designation" id="payroll_signatory_accountant_designation" class="form-input"
                           value="{{ old('payroll_signatory_accountant_designation', $settings['payroll_signatory_accountant_designation']->value ?? '') }}">
                </div>
            </div>
        </section>

        <section class="payroll-section" style="margin-bottom:24px">
            <h2>City Treasurer</h2>
            <div class="grid">
                <div class="form-group">
                    <label for="payroll_signatory_treasurer_name">Name</label>
                    <input type="text" name="payroll_signatory_treasurer_name" id="payroll_signatory_treasurer_name" class="form-input"
                           value="{{ old('payroll_signatory_treasurer_name', $settings['payroll_signatory_treasurer_name']->value ?? '') }}">
                </div>
                <div class="form-group">
                    <label for="payroll_signatory_treasurer_designation">Designation</label>
                    <input type="text" name="payroll_signatory_treasurer_designation" id="payroll_signatory_treasurer_designation" class="form-input"
                           value="{{ old('payroll_signatory_treasurer_designation', $settings['payroll_signatory_treasurer_designation']->value ?? '') }}">
                </div>
            </div>
        </section>

        <section class="payroll-section" style="margin-bottom:24px">
            <h2>Cash Clerk / Disbursing Officer</h2>
            <div class="grid">
                <div class="form-group">
                    <label for="payroll_signatory_cash_clerk_names">Name(s)</label>
                    <input type="text" name="payroll_signatory_cash_clerk_names" id="payroll_signatory_cash_clerk_names" class="form-input"
                           value="{{ old('payroll_signatory_cash_clerk_names', $settings['payroll_signatory_cash_clerk_names']->value ?? '') }}">
                </div>
                <div class="form-group">
                    <label for="payroll_signatory_cash_clerk_designation">Designation</label>
                    <input type="text" name="payroll_signatory_cash_clerk_designation" id="payroll_signatory_cash_clerk_designation" class="form-input"
                           value="{{ old('payroll_signatory_cash_clerk_designation', $settings['payroll_signatory_cash_clerk_designation']->value ?? '') }}">
                </div>
            </div>
        </section>

        <section class="payroll-section" style="margin-bottom:24px">
            <h2>Payslip</h2>
            <div class="grid">
                <div class="form-group">
                    <label for="payroll_signatory_payslip_prepared_by_name">Prepared By - Name</label>
                    <input type="text" name="payroll_signatory_payslip_prepared_by_name" id="payroll_signatory_payslip_prepared_by_name" class="form-input"
                           value="{{ old('payroll_signatory_payslip_prepared_by_name', $settings['payroll_signatory_payslip_prepared_by_name']->value ?? '') }}">
                </div>
                <div class="form-group">
                    <label for="payroll_signatory_payslip_prepared_by_designation">Prepared By - Designation</label>
                    <input type="text" name="payroll_signatory_payslip_prepared_by_designation" id="payroll_signatory_payslip_prepared_by_designation" class="form-input"
                           value="{{ old('payroll_signatory_payslip_prepared_by_designation', $settings['payroll_signatory_payslip_prepared_by_designation']->value ?? '') }}">
                </div>
                <div class="form-group">
                    <label>Certified By - Name</label>
                    <p class="form-input" style="background:#f8fafc">{{ $hrManagerSettings->hr_manager_name ?? '—' }}</p>
                </div>
                <div class="form-group">
                    <label>Certified By - Designation</label>
                    <p class="form-input" style="background:#f8fafc">{{ $hrManagerSettings->hr_manager_designation ?? '—' }}</p>
                </div>
            </div>
            <p class="text-muted" style="margin-top:8px">
                "Certified By" is the shared HR Manager name/designation also used on Job Order Roster and Leave Request documents — edit it on HR Manager Settings.
            </p>
        </section>

        <div class="form-actions">
            <button type="button" class="btn" onclick="confirmSaveSignatories()"><i class="fas fa-floppy-disk"></i> Save</button>
        </div>
    </form>
@endsection

@section('page_scripts_after')
<script>
function confirmSaveSignatories() {
    const message = 'This updates the name/designation printed on every future Payroll Form export.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Save signatories?',
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Save',
        }).then((result) => { if (result.isConfirmed) document.getElementById('signatories-form').submit(); });
    } else if (confirm(message)) {
        document.getElementById('signatories-form').submit();
    }
}
</script>
@endsection
