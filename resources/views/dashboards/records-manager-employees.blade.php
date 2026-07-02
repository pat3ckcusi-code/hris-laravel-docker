@extends('dashboards.layout', [
    'title' => 'Employee Management',
    'subtitle' => 'Create and maintain employee records in HRIS.',
])

@section('tiles')
    <article class="tile">
        <strong>Total Employees</strong>
        {{ $statusSummary['total'] }} records in the HRIS registry.
    </article>
    <article class="tile">
        <strong>Active Profiles</strong>
        {{ $statusSummary['active'] }} employee records marked Active.
    </article>
    <article class="tile">
        <strong>Non-Active Profiles</strong>
        {{ $statusSummary['inactive'] }} records require review or follow-up.
    </article>
@endsection

@section('top_actions')
    <button type="button" class="btn" id="openAddEmployeeModal">Add New Employee</button>
    <button type="button" class="btn" id="openImportEmployeeModal">Import Employees</button>
@endsection

@section('page_head')
    @vite('resources/css/records_manager.css')
    @include('partials.table-styles')
@endsection

@section('content')
    {{-- Flash messages are handled via SweetAlert2 popups (see page_scripts) --}}

    <dialog id="addEmployeeModal" class="employee-modal">
        <form method="dialog" class="modal-top-actions">
            <button type="submit" class="modal-close" aria-label="Close">x</button>
        </form>

        <header>
            <h3>Add New Employee</h3>
            <span class="record-email">Create an employee account and initial access profile.</span>
        </header>

        <form
            id="addEmployeeForm"
            method="POST"
            action="{{ route('dashboard.records-manager.users.store') }}"
            class="record-form"
            data-custom-submit="true"
        >
            @csrf
            <input type="hidden" name="create_form" value="1">

            <label>
                Last Name
                <input type="text" name="last_name" value="{{ old('last_name') }}" data-uppercase-input required>
            </label>

            <label>
                First Name
                <input type="text" name="first_name" value="{{ old('first_name') }}" data-uppercase-input required>
            </label>

            <label>
                Middle Name
                <input type="text" name="middle_name" value="{{ old('middle_name') }}" data-uppercase-input>
            </label>

            <label>
                Email
                <input type="email" name="email" value="{{ old('email') }}" required>
            </label>

            <label>
                Agency Employee No.
                <input type="text" id="addEmpNo" name="EmpNo" value="{{ old('EmpNo') }}"
                       readonly data-next-sequential-by-type="{{ json_encode($nextSequentialByType) }}">
            </label>

            <label>
                Designation
                <input type="text" name="designation" value="{{ old('designation') }}" data-uppercase-input>
            </label>

            <label>
                Department
                <select name="Dept_id">
                    <option value="">Not assigned</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->Dept_id }}" @selected((string) old('Dept_id') === (string) $department->Dept_id)>
                            {{ $department->Dept_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Date Hired
                <input type="date" name="date_hired" required>
            </label>

            <label>
                Employee Type (for Employee access)
                <select name="employee_type">
                    <option value="">Select employee type</option>
                    @foreach ($employeeTypes as $employeeType)
                        <option value="{{ $employeeType }}" @selected(old('employee_type') === $employeeType)>
                            {{ $employeeType }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Access Level
                <select name="access_level" required>
                    @foreach ($accessLevels as $accessLevel)
                        <option value="{{ $accessLevel }}" @selected(old('access_level', 'employee') === $accessLevel)>
                            {{ ucwords($accessLevel) }}
                        </option>
                    @endforeach
                </select>
            </label>

            <p class="create-note">
                A default account password will be sent to the employee email.
                The user will be required to change it at first login.
            </p>

            <button type="submit" class="record-btn">Create Employee</button>
        </form>
    </dialog>

    <dialog id="importEmployeeModal" class="employee-modal">
        <form method="dialog" class="modal-top-actions">
            <button type="submit" class="modal-close" aria-label="Close">x</button>
        </form>

        <header>
            <h3>Import Employees</h3>
            <span class="record-email">Upload a filled template to create multiple employee accounts at once.</span>
        </header>

        <div style="margin-bottom:1rem;">
            <a href="{{ route('dashboard.records-manager.employees.import-template') }}" class="btn" style="font-size:0.85rem;">
                Download Template
            </a>
            <span style="font-size:0.8rem; color:#6b7280; margin-left:0.5rem;">Fill in the template then upload it below.</span>
        </div>

        <form id="importEmployeeForm" class="record-form" data-custom-submit="true">
            @csrf
            <label>
                Excel / CSV File
                <input type="file" id="importFile" name="import_file" accept=".xlsx,.xls,.csv" required>
            </label>
            <p class="create-note">
                Required columns: Last Name, First Name, Email, Date Hired, Employee Type, Access Level.<br>
                Credential emails will be sent to each employee automatically.
            </p>
            <button type="submit" class="record-btn">Import</button>
        </form>
    </dialog>

    <section aria-label="Employee records management">
        <form method="GET" action="{{ route('dashboard.records-manager.employees') }}" class="table-toolbar" aria-label="Employee list filters">
            <div class="toolbar-inputs">
                <label>
                    Search
                    <input type="text" name="search" value="{{ $search }}" placeholder="Name, email, or employee no.">
                </label>

                <label>
                    Status
                    <select name="status">
                        <option value="">All status</option>
                        @foreach (['Active', 'Inactive', 'Separated'] as $status)
                            <option value="{{ $status }}" @selected($statusFilter === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Department
                    <select name="department">
                        <option value="">All departments</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->Dept_id }}" @selected((string) $departmentFilter === (string) $department->Dept_id)>
                                {{ $department->Dept_name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Employee Type
                    <select name="employee_type">
                        <option value="">All types</option>
                        @foreach ($employeeTypes as $employeeType)
                            <option value="{{ $employeeType }}" @selected($employeeTypeFilter === $employeeType)>
                                {{ $employeeType }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="toolbar-actions">
                <button type="submit" class="record-btn toolbar-btn">Apply</button>
                <a href="{{ route('dashboard.records-manager.employees') }}" class="toolbar-reset">Reset</a>
            </div>
        </form>

        <div class="table-wrap">
            <table id="employeeTable" class="employee-table display hris-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Last Name</th>
                        <th>First Name</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th>Date Hired</th>
                        <th>Email</th>
                        <th>Agency Employee No.</th>
                        <th>Access Level</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($employees as $employee)
                        @php
                            $departmentName = optional($departments->firstWhere('Dept_id', $employee->Dept_id))->Dept_name ?? 'Not assigned';
                        @endphp
                        <tr>
                            <td>{{ $employee->last_name ?: '-' }}</td>
                            <td>{{ $employee->first_name ?: '-' }}</td>
                            <td>{{ $employee->designation ?: '-' }}</td>
                            <td>{{ $departmentName }}</td>
                            <td>{{ optional($employee->date_hired)->format('Y-m-d') ?: '-' }}</td>
                            <td>{{ $employee->email }}</td>
                            <td>{{ $employee->EmpNo ?: '-' }}</td>
                            <td>{{ ucwords((string) $employee->access_level) }}</td>
                            <td>
                                @php
                                    $empBadgeClass = match(strtolower((string) ($employee->Status ?: 'Unset'))) {
                                        'active' => 'badge-active',
                                        'inactive' => 'badge-inactive',
                                        'separated' => 'badge-separated',
                                        default => 'badge-default',
                                    };
                                @endphp
                                <span class="badge {{ $empBadgeClass }}">
                                    {{ $employee->Status ?: 'Unset' }}
                                </span>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn-sm btn-view open-update-modal"
                                    data-user-id="{{ $employee->id }}"
                                    data-update-url="{{ route('dashboard.records-manager.users.update', $employee) }}"
                                    data-last-name="{{ $employee->last_name }}"
                                    data-first-name="{{ $employee->first_name }}"
                                    data-middle-name="{{ $employee->middle_name }}"
                                    data-email="{{ $employee->email }}"
                                    data-emp-no="{{ $employee->EmpNo }}"
                                    data-designation="{{ $employee->designation }}"
                                    data-dept-id="{{ $employee->Dept_id }}"
                                    data-date-hired="{{ optional($employee->date_hired)->format('Y-m-d') }}"
                                    data-status="{{ $employee->Status }}"
                                    data-employee-type="{{ $employee->employee_type }}"
                                    data-access-level="{{ strtolower((string) $employee->access_level) }}"
                                >
                                    Update
                                </button>
                                <button type="button" class="btn-sm btn-reject" onclick="confirmDelete({{ $employee->id }}, '{{ route('dashboard.records-manager.users.destroy', $employee) }}')" style="margin-left:8px">Delete</button>
                                <button type="button" class="btn-sm" style="margin-left:8px; background:#6366f1; color:#fff;" onclick="confirmResetPassword({{ $employee->id }}, '{{ route('records-manager.employees.reset-password', $employee->id) }}', '{{ e($employee->email) }}')">Reset Password</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination handled by DataTables --}}
    </section>

    <dialog id="updateEmployeeModal" class="employee-modal">
        <form method="dialog" class="modal-top-actions">
            <button type="submit" class="modal-close" aria-label="Close">x</button>
        </form>

        <header>
            <h3>Update Employee</h3>
            <span class="record-email">Edit the selected employee information and save changes.</span>
        </header>

        <form
            id="updateEmployeeForm"
            method="POST"
            action=""
            class="record-form"
            data-processing-title="Updating employee record"
            data-processing-text="Saving the selected employee changes. Please wait."
        >
            @csrf
            @method('PUT')
            <input type="hidden" name="update_form" value="1">
            <input type="hidden" name="update_employee_id" id="updateEmployeeId" value="{{ old('update_employee_id') }}">

            <label>
                Last Name
                <input type="text" name="last_name" id="updateLastName" value="{{ old('last_name') }}" data-uppercase-input required>
            </label>

            <label>
                First Name
                <input type="text" name="first_name" id="updateFirstName" value="{{ old('first_name') }}" data-uppercase-input required>
            </label>

            <label>
                Middle Name
                <input type="text" name="middle_name" id="updateMiddleName" value="{{ old('middle_name') }}" data-uppercase-input>
            </label>

            <label>
                Email
                <input type="email" name="email" id="updateEmail" value="{{ old('email') }}" required>
            </label>

            <label>
                Agency Employee No.
                <input type="text" name="EmpNo" id="updateEmpNo" value="{{ old('EmpNo') }}" data-uppercase-input>
            </label>

            <label>
                Designation
                <input type="text" name="designation" id="updateDesignation" value="{{ old('designation') }}" data-uppercase-input>
            </label>

            <label>
                Department
                <select name="Dept_id" id="updateDeptId">
                    <option value="">Not assigned</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->Dept_id }}" @selected((string) old('Dept_id') === (string) $department->Dept_id)>
                            {{ $department->Dept_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Date Hired
                <input type="date" name="date_hired" id="updateDateHired" required>
            </label>

            <label>
                Status
                <select name="Status" id="updateStatus">
                    <option value="">Unset</option>
                    @foreach (['Active', 'Inactive', 'Separated'] as $status)
                        <option value="{{ $status }}" @selected(old('Status') === $status)>
                            {{ $status }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Employee Type
                <select name="employee_type" id="updateEmployeeType">
                    <option value="">Select employee type</option>
                    @foreach ($employeeTypes as $employeeType)
                        <option value="{{ $employeeType }}" @selected(old('employee_type') === $employeeType)>
                            {{ $employeeType }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Access Level
                <select name="access_level" id="updateAccessLevel" required>
                    @foreach ($accessLevels as $accessLevel)
                        <option value="{{ $accessLevel }}" @selected(old('access_level') === $accessLevel)>
                            {{ ucwords($accessLevel) }}
                        </option>
                    @endforeach
                </select>
            </label>

            <button type="submit" class="record-btn">Update</button>
        </form>
    </dialog>
@endsection

@section('page_scripts')
    @vite('resources/js/records_manager.js')
    <script>
        (function () {
            const addModal = document.getElementById('addEmployeeModal');
            const updateModal = document.getElementById('updateEmployeeModal');
            const openButton = document.getElementById('openAddEmployeeModal');
            const updateForm = document.getElementById('updateEmployeeForm');
            const updateEmployeeId = document.getElementById('updateEmployeeId');
            const updateLastName = document.getElementById('updateLastName');
            const updateFirstName = document.getElementById('updateFirstName');
            const updateMiddleName = document.getElementById('updateMiddleName');
            const updateEmail = document.getElementById('updateEmail');
            const updateEmpNo = document.getElementById('updateEmpNo');
            const updateDesignation = document.getElementById('updateDesignation');
            const updateDeptId = document.getElementById('updateDeptId');
            const updateDateHired = document.getElementById('updateDateHired');
            const updateStatus = document.getElementById('updateStatus');
            const updateEmployeeType = document.getElementById('updateEmployeeType');
            const updateAccessLevel = document.getElementById('updateAccessLevel');

            const openCreateModal = function () {
                if (!addModal || typeof addModal.showModal !== 'function') {
                    return;
                }

                if (!addModal.open) {
                    addModal.showModal();
                }
            };

            const openUpdateModal = function () {
                if (!updateModal || typeof updateModal.showModal !== 'function') {
                    return;
                }

                if (!updateModal.open) {
                    updateModal.showModal();
                }
            };

            if (!addModal || !openButton || typeof addModal.showModal !== 'function') {
                return;
            }

            openButton.addEventListener('click', function () {
                openCreateModal();
            });

            // Auto-fill EmpNo (read-only) when date_hired or employee_type is picked (format: YY + 5-digit per-type sequential)
            var addEmpNoInput    = document.getElementById('addEmpNo');
            var addDateHiredInput = document.querySelector('#addEmployeeForm [name="date_hired"]');
            var addEmpTypeSelect  = document.querySelector('#addEmployeeForm [name="employee_type"]');

            if (addEmpNoInput && addDateHiredInput && addEmpTypeSelect) {
                var nextSeqByType = JSON.parse(addEmpNoInput.dataset.nextSequentialByType || '{}');

                function buildEmpNo(dateValue, empType) {
                    var year = (dateValue || '').slice(2, 4);
                    var seq  = nextSeqByType[empType] || '';
                    return (year && seq) ? year + seq : '';
                }

                function tryAutoFill() {
                    addEmpNoInput.value = buildEmpNo(addDateHiredInput.value, addEmpTypeSelect.value);
                }

                addDateHiredInput.addEventListener('change', tryAutoFill);
                addEmpTypeSelect.addEventListener('change', tryAutoFill);

                openButton.addEventListener('click', function () {
                    addEmpNoInput.value = buildEmpNo(addDateHiredInput.value, addEmpTypeSelect.value);
                });
            }

            document.querySelectorAll('.open-update-modal').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!updateForm) {
                        return;
                    }

                    updateForm.action = button.dataset.updateUrl || '';

                    if (updateEmployeeId) updateEmployeeId.value = button.dataset.userId || '';
                    if (updateLastName) updateLastName.value = button.dataset.lastName || '';
                    if (updateFirstName) updateFirstName.value = button.dataset.firstName || '';
                    if (updateMiddleName) updateMiddleName.value = button.dataset.middleName || '';
                    if (updateEmail) updateEmail.value = button.dataset.email || '';
                    if (updateEmpNo) updateEmpNo.value = button.dataset.empNo || '';
                    if (updateDesignation) updateDesignation.value = button.dataset.designation || '';
                    if (updateDateHired) updateDateHired.value = button.dataset.dateHired || '';
                    if (updateDeptId) updateDeptId.value = button.dataset.deptId || '';
                    if (updateStatus) updateStatus.value = button.dataset.status || '';
                    if (updateEmployeeType) updateEmployeeType.value = button.dataset.employeeType || '';
                    if (updateAccessLevel) updateAccessLevel.value = button.dataset.accessLevel || '';

                    openUpdateModal();
                });
            });

            @if (old('update_form') === '1' && $errors->any())
            @php
                $updateEmployee = $employees->firstWhere('id', (int) old('update_employee_id'));
            @endphp
            @if ($updateEmployee)
            if (updateForm) {
                updateForm.action = @json(route('dashboard.records-manager.users.update', $updateEmployee));
            }
            @endif
            Swal.fire({
                icon: 'error',
                title: 'Unable to save changes',
                text: @json(implode("\n", $errors->all())),
                confirmButtonColor: '#ea580c',
            }).then(function () {
                openUpdateModal();
            });
            @endif
        })();

        // Add New Employee - confirmation + AJAX submit
        (function ($) {
            var form = document.getElementById('addEmployeeForm');
            if (!form) return;

            $(form).on('submit', function (e) {
                e.preventDefault();
                var $form = $(this);
                var modal = document.getElementById('addEmployeeModal');

                // Close the native dialog before opening SweetAlert so it doesn't
                // render behind the dialog's top-layer stacking context.
                if (modal && modal.open) modal.close();

                Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to add this employee?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, add',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#f06c00',
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        if (modal && typeof modal.showModal === 'function') modal.showModal();
                        return;
                    }

                    Swal.fire({
                        title: 'Creating employee account',
                        text: 'Saving the record and sending the default password email. Please wait.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: function () { Swal.showLoading(); },
                    });

                    $.ajax({
                        url: form.action,
                        type: 'POST',
                        data: $form.serialize(),
                        headers: { 'Accept': 'application/json' },
                        success: function (res) {
                            Swal.fire('Success', res.message || 'Employee account created.', 'success')
                                .then(function () { window.location.reload(); });
                        },
                        error: function (xhr) {
                            var msg = 'An unexpected error occurred.';
                            if (xhr.responseJSON) {
                                if (xhr.responseJSON.errors) {
                                    msg = Object.values(xhr.responseJSON.errors).flat().join('\n');
                                } else if (xhr.responseJSON.message) {
                                    msg = xhr.responseJSON.message;
                                }
                            }
                            Swal.fire('Error', msg, 'error')
                                .then(function () {
                                    if (modal && typeof modal.showModal === 'function') modal.showModal();
                                });
                        },
                    });
                });
            });
        })(jQuery);

        // Import Employees modal
        (function () {
            var importModal  = document.getElementById('importEmployeeModal');
            var importButton = document.getElementById('openImportEmployeeModal');
            var importForm   = document.getElementById('importEmployeeForm');

            if (!importModal || !importButton || !importForm) return;

            importButton.addEventListener('click', function () {
                if (!importModal.open) importModal.showModal();
            });

            importForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var fileInput = document.getElementById('importFile');
                if (!fileInput || !fileInput.files.length) {
                    Swal.fire('No file selected', 'Please choose an Excel or CSV file to upload.', 'warning');
                    return;
                }

                if (importModal.open) importModal.close();

                Swal.fire({
                    title: 'Importing employees',
                    text: 'Processing your file. Please wait.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: function () { Swal.showLoading(); },
                });

                var formData = new FormData();
                formData.append('import_file', fileInput.files[0]);
                formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

                fetch('{{ route('dashboard.records-manager.employees.import') }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData,
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success === false) {
                        Swal.fire('Error', data.message || 'An error occurred.', 'error');
                        return;
                    }

                    var failedHtml = '';
                    if (data.failed && data.failed.length) {
                        failedHtml = '<br><br><strong style="color:#dc2626;">Failed rows:</strong><ul style="text-align:left;margin-top:0.5rem;color:#dc2626;">';
                        data.failed.forEach(function (f) {
                            failedHtml += '<li>Row ' + f.row + ': ' + f.errors.join(', ') + '</li>';
                        });
                        failedHtml += '</ul>';
                    }

                    var warningsHtml = '';
                    if (data.warnings && data.warnings.length) {
                        warningsHtml = '<br><br><strong style="color:#d97706;">Warnings:</strong><ul style="text-align:left;margin-top:0.5rem;color:#d97706;">';
                        data.warnings.forEach(function (w) {
                            warningsHtml += '<li>Row ' + w.row + ': ' + w.message + '</li>';
                        });
                        warningsHtml += '</ul>';
                    }

                    var icon  = data.imported > 0 ? 'success' : 'warning';
                    var title = data.imported > 0 ? 'Import Complete' : 'No Records Imported';
                    var msg   = data.imported + ' employee' + (data.imported !== 1 ? 's' : '') + ' imported.';
                    if (data.failed && data.failed.length) {
                        msg += ' ' + data.failed.length + ' row' + (data.failed.length !== 1 ? 's' : '') + ' failed.';
                    }

                    Swal.fire({
                        icon: icon,
                        title: title,
                        html: msg + warningsHtml + failedHtml,
                        confirmButtonColor: '#f06c00',
                    }).then(function () {
                        if (data.imported > 0) window.location.reload();
                    });
                })
                .catch(function () {
                    Swal.fire('Error', 'An unexpected error occurred. Please try again.', 'error');
                });
            });
        })();

        // Initialize DataTables (guard to avoid reinitialization)
        document.addEventListener('DOMContentLoaded', function () {
            if (window.jQuery && typeof jQuery().DataTable === 'function') {
                if (!$.fn.dataTable.isDataTable('#employeeTable')) {
                    $('#employeeTable').DataTable({
                        paging: true,
                        pageLength: 25,
                        lengthChange: true,
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                        searching: true,
                        info: true,
                        autoWidth: false,
                        language: {
                            paginate: { previous: 'Prev', next: 'Next' },
                            emptyTable: 'No employee records found.'
                        }
                    });
                }
            }
        });

        // Expose confirmDelete to global scope for inline onclick
        window.confirmDelete = function (employeeId, deleteUrl) {
            confirmDelete(employeeId, deleteUrl);
        };

        // Reset Password confirmation modal
        window.confirmResetPassword = function (employeeId, resetUrl, email) {
            if (typeof Swal === 'undefined') {
                if (!confirm('Reset password for ' + email + '? A temporary password will be shown on screen.')) return;
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = resetUrl;
                var csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = '_token';
                csrf.value = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
                form.appendChild(csrf);
                document.body.appendChild(form);
                form.submit();
                return;
            }

            Swal.fire({
                title: 'Reset Password?',
                html: 'A temporary password will be generated for <strong>' + email + '</strong>.<br><br>Give the password to the employee in person. They will be required to change it on next login.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#6366f1',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Reset Password',
                cancelButtonText: 'Cancel',
            }).then(function (result) {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'Resetting password...', text: 'Please wait.', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
                    fetch(resetUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                    })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.status === 'success' && data.temporary_password) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Password Reset',
                                html: 'Give this temporary password to the employee:<br><br>'
                                    + '<code style="font-size:1.4rem;font-weight:700;letter-spacing:0.05em;background:#f3f4f6;padding:0.4rem 0.8rem;border-radius:6px;">' + data.temporary_password + '</code>'
                                    + '<br><br><small>The employee will be required to change it on next login.</small>',
                                confirmButtonColor: '#6366f1',
                                confirmButtonText: 'Done',
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'An unexpected error occurred.',
                                confirmButtonColor: '#6366f1',
                            });
                        }
                    })
                    .catch(function () {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.', confirmButtonColor: '#ea580c' });
                    });
                }
            });
        };
    </script>
    @if (session('status') && session('message'))
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var _status = @json(session('status'));
            var _message = @json(session('message'));
            if (window.Swal && typeof Swal.fire === 'function') {
                Swal.fire({
                    icon: _status === 'success' ? 'success' : (_status === 'error' ? 'error' : 'info'),
                    title: _status === 'success' ? 'Success' : (_status ? (_status.charAt(0).toUpperCase() + _status.slice(1)) : 'Notice'),
                    text: _message,
                    confirmButtonText: 'OK'
                });
            } else {
                alert(_message);
            }
        });
    </script>
    @endif
@endsection
