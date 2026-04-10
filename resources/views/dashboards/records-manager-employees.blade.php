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
            method="POST"
            action="{{ route('dashboard.records-manager.users.store') }}"
            class="record-form"
            data-processing-submit
            data-processing-title="Creating employee account"
            data-processing-text="Saving the employee record and sending the default password email. Please wait."
            data-processing-button-text="Creating..."
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
                Employee No.
                <input type="text" name="EmpNo" value="{{ old('EmpNo') }}" data-uppercase-input>
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
            </div>

            <div class="toolbar-actions">
                <button type="submit" class="record-btn toolbar-btn">Apply</button>
                <a href="{{ route('dashboard.records-manager.employees') }}" class="toolbar-reset">Reset</a>
            </div>
        </form>

        <div class="table-wrap">
            <table id="employeeTable" class="employee-table display leave-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Last Name</th>
                        <th>First Name</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th>Date Hired</th>
                        <th>Email</th>
                        <th>Employee No.</th>
                        <th>Access Level</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employees as $employee)
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
                    @empty
                        <tr>
                                    <td colspan="10">
                                <p class="empty-state">No employee records found.</p>
                            </td>
                        </tr>
                    @endforelse
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
            data-processing-submit
            data-processing-title="Updating employee record"
            data-processing-text="Saving the selected employee changes. Please wait."
            data-processing-button-text="Updating..."
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
                Employee No.
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
    <!-- DataTables (kept via CDN here) -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
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

            @if (old('create_form') === '1' && $errors->any())
            Swal.fire({
                icon: 'error',
                title: 'Unable to save changes',
                text: @json(implode("\n", $errors->all())),
                confirmButtonColor: '#ea580c',
            }).then(function () {
                openCreateModal();
            });
            @elseif (old('update_form') === '1' && $errors->any())
            @php
                $updateEmployee = $employees->getCollection()->firstWhere('id', (int) old('update_employee_id'));
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
            @elseif (old('create_form') === '1')
            openCreateModal();
            @endif
        })();

        // Initialize DataTables (guard to avoid reinitialization)
        document.addEventListener('DOMContentLoaded', function () {
            if (window.jQuery && typeof jQuery().DataTable === 'function') {
                if (!$.fn.dataTable.isDataTable('#employeeTable')) {
                    $('#employeeTable').DataTable({
                        paging: true,
                        pageLength: 10,
                        lengthChange: true,
                        lengthMenu: [10, 25, 50, 100],
                        searching: true,
                        info: true,
                        language: {
                            paginate: { previous: 'Prev', next: 'Next' }
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
                if (!confirm('Reset password for ' + email + '? A temporary password will be emailed.')) return;
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
                html: 'A temporary password will be generated and sent to:<br><strong>' + email + '</strong><br><br>The employee will be required to change it on next login.',
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
                        Swal.fire({
                            icon: data.status === 'success' ? 'success' : 'error',
                            title: data.status === 'success' ? 'Password Reset' : 'Error',
                            text: data.message,
                            confirmButtonColor: '#6366f1',
                        });
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
