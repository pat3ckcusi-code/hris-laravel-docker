@extends('dashboards.layout', [
    'title' => 'Department Management',
    'subtitle' => 'Review department structure and assignment coverage.',
])

@section('tiles')
    <article class="tile">
        <strong>Total Departments</strong>
        {{ $totalDepartments }} configured departments.
    </article>
    <article class="tile">
        <strong>Assigned Departments</strong>
        {{ $assignedDepartmentsCount }} with employee assignments.
    </article>
    <article class="tile">
        <strong>Unassigned Departments</strong>
        {{ $unassignedDepartmentsCount }} with no employees.
    </article>
@endsection

@section('top_actions')
    <button type="button" class="btn" id="openAddDepartmentModal">Add Department</button>
@endsection

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <dialog id="addDepartmentModal" class="dept-modal">
        <form method="dialog" class="modal-top-actions">
            <button type="submit" class="modal-close" aria-label="Close">x</button>
        </form>

        <header>
            <h3>Add Department</h3>
            <span class="modal-subtitle">Create a new department and assign an initial reference employee.</span>
        </header>

        <form
            method="POST"
            action="{{ route('dashboard.records-manager.departments.store') }}"
            class="department-form"
            data-processing-submit
            data-processing-title="Creating department"
            data-processing-text="Saving the new department information. Please wait."
            data-processing-button-text="Creating..."
        >
            @csrf
            <input type="hidden" name="create_department_form" value="1">

            <label>
                Department Code
                <input type="text" name="DeptCode" value="{{ old('DeptCode') }}" data-uppercase-input placeholder="Leave blank to auto-generate">
            </label>

            <label>
                Department Name
                <input type="text" name="Dept_name" value="{{ old('Dept_name') }}" data-uppercase-input required>
            </label>

            <label>
                Reference Employee No.
                <select name="EmpNo" required>
                    <option value="">-- Select Department Head --</option>
                    @foreach ($departmentHeadUsers as $deptHead)
                        <option value="{{ $deptHead->EmpNo }}" @selected(old('EmpNo') === $deptHead->EmpNo)>
                            {{ $deptHead->EmpNo }} — {{ $deptHead->last_name }}, {{ $deptHead->first_name }} {{ $deptHead->middle_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Administrative Officer
                <select name="ao_emp_no">
                    <option value="">— None —</option>
                    @foreach ($adminOfficerUsers as $aoUser)
                        <option value="{{ $aoUser->EmpNo }}" @selected(old('ao_emp_no') === $aoUser->EmpNo)>
                            {{ $aoUser->EmpNo }} — {{ $aoUser->last_name }}, {{ $aoUser->first_name }} {{ $aoUser->middle_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Designation
                <input type="text" name="Designation" value="{{ old('Designation') }}" data-uppercase-input required>
            </label>

            <label>
                Parent Department
                <select name="parent_dept_id">
                    <option value="">None</option>
                    @foreach ($allDepartments as $department)
                        <option value="{{ $department->Dept_id }}" @selected((string) old('parent_dept_id') === (string) $department->Dept_id)>
                            {{ $department->Dept_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <button type="submit" class="record-btn">Create Department</button>
        </form>
    </dialog>

    <dialog id="updateDepartmentModal" class="dept-modal">
        <form method="dialog" class="modal-top-actions">
            <button type="submit" class="modal-close" aria-label="Close">x</button>
        </form>

        <header>
            <h3>Update Department</h3>
            <span class="modal-subtitle">Edit department details and assignment references.</span>
        </header>

        <form
            method="POST"
            action=""
            id="updateDepartmentForm"
            class="department-form"
            data-processing-submit
            data-processing-title="Updating department"
            data-processing-text="Saving department updates. Please wait."
            data-processing-button-text="Updating..."
        >
            @csrf
            @method('PUT')
            <input type="hidden" name="update_department_form" value="1">
            <input type="hidden" name="update_department_id" id="updateDepartmentId" value="{{ old('update_department_id') }}">

            <label>
                Department Code
                <input type="text" name="DeptCode" id="updateDeptCode" value="{{ old('DeptCode') }}" data-uppercase-input placeholder="Leave blank to auto-generate">
            </label>

            <label>
                Department Name
                <input type="text" name="Dept_name" id="updateDeptName" value="{{ old('Dept_name') }}" data-uppercase-input required>
            </label>

            <label>
                Reference Employee No.
                <select name="EmpNo" id="updateDeptEmpNo">
                    <option value="">-- Select Department Head --</option>
                    @foreach ($departmentHeadUsers as $deptHead)
                        <option value="{{ $deptHead->EmpNo }}" @selected(old('EmpNo') === $deptHead->EmpNo)>
                            {{ $deptHead->EmpNo }} — {{ $deptHead->last_name }}, {{ $deptHead->first_name }} {{ $deptHead->middle_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Administrative Officer
                <select name="ao_emp_no" id="updateDeptAoEmpNo">
                    <option value="">— None —</option>
                    @foreach ($adminOfficerUsers as $aoUser)
                        <option value="{{ $aoUser->EmpNo }}" @selected(old('ao_emp_no') === $aoUser->EmpNo)>
                            {{ $aoUser->EmpNo }} — {{ $aoUser->last_name }}, {{ $aoUser->first_name }} {{ $aoUser->middle_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Designation
                <input type="text" name="Designation" id="updateDeptDesignation" value="{{ old('Designation') }}" data-uppercase-input required>
            </label>

            <label>
                Parent Department
                <select name="parent_dept_id" id="updateParentDeptId">
                    <option value="">None</option>
                    @foreach ($allDepartments as $parentDepartment)
                        <option value="{{ $parentDepartment->Dept_id }}" @selected((string) old('parent_dept_id') === (string) $parentDepartment->Dept_id)>
                            {{ $parentDepartment->Dept_name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <button type="submit" class="record-btn">Update Department</button>
        </form>
    </dialog>

    <form method="GET" action="{{ route('dashboard.records-manager.departments') }}" class="table-toolbar" aria-label="Department list filters">
        <div class="toolbar-inputs">
            <label>
                Search Department
                <input type="text" name="search" value="{{ $search }}" placeholder="Department name">
            </label>

            <label>
                Assignment Status
                <select name="status">
                    <option value="">All departments</option>
                    <option value="assigned" @selected($statusFilter === 'assigned')>Assigned</option>
                    <option value="unassigned" @selected($statusFilter === 'unassigned')>Unassigned</option>
                </select>
            </label>
        </div>

        <div class="toolbar-actions">
            <button type="submit" class="record-btn toolbar-btn">Apply</button>
            <a href="{{ route('dashboard.records-manager.departments') }}" class="toolbar-reset">Reset</a>
        </div>
    </form>

    <section class="table-wrap" aria-label="Department listing">
        <table id="departmentTable" class="dept-table display leave-table" style="width:100%">
            <thead>
                <tr>
                    <th>Department Code</th>
                    <th>Department Name</th>
                    <th>Reference Emp No.</th>
                    <th>Admin Officer</th>
                    <th>Designation</th>
                    <th>Parent</th>
                    <th>Employee Count</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($departments as $department)
                    @php
                        $count = (int) ($departmentEmployeeCounts[$department->Dept_id] ?? 0);
                        $parentDepartmentName = $allDepartments->firstWhere('Dept_id', $department->parent_dept_id)?->Dept_name;
                    @endphp
                    <tr>
                        <td>{{ $department->DeptCode ?: '-' }}</td>
                        <td>{{ $department->Dept_name }}</td>
                        <td>{{ $department->EmpNo ?: '-' }}</td>
                        <td>{{ $department->ao_emp_no ?: '—' }}</td>
                        <td>{{ $department->Designation ?: '-' }}</td>
                        <td>{{ $parentDepartmentName ?: 'None' }}</td>
                        <td>{{ $count }}</td>
                        <td>
                            <span class="badge {{ $count > 0 ? 'badge-approved' : 'badge-draft' }}">
                                {{ $count > 0 ? 'Active' : 'No Assigned Employee' }}
                            </span>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="btn-sm btn-view open-update-department-modal"
                                data-dept-id="{{ $department->Dept_id }}"
                                data-dept-code="{{ $department->DeptCode }}"
                                data-dept-name="{{ $department->Dept_name }}"
                                data-emp-no="{{ $department->EmpNo }}"
                                data-ao-emp-no="{{ $department->ao_emp_no }}"
                                data-designation="{{ $department->Designation }}"
                                data-parent-dept-id="{{ $department->parent_dept_id }}"
                            >
                                Update
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Pagination handled by DataTables --}}
@endsection

@section('page_scripts')
    @vite('resources/js/records_manager.js')
    <!-- SweetAlert2 and DataTables CDN -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.jQuery && typeof jQuery().DataTable === 'function') {
                if (!$.fn.dataTable.isDataTable('#departmentTable')) {
                    $('#departmentTable').DataTable({
                        pageLength: 10,
                        lengthChange: true,
                        lengthMenu: [10, 25, 50, 100],
                        responsive: true,
                        ordering: true,
                        searching: true,
                        paging: true,
                        info: true,
                        language: { emptyTable: 'No departments found.', paginate: { previous: 'Prev', next: 'Next' } }
                    });
                }
            }
        });
    </script>

    <script>
        (function () {
            const addModal = document.getElementById('addDepartmentModal');
            const updateModal = document.getElementById('updateDepartmentModal');
            const openButton = document.getElementById('openAddDepartmentModal');
            const updateForm = document.getElementById('updateDepartmentForm');
            const updateDepartmentId = document.getElementById('updateDepartmentId');
            const updateDeptCode = document.getElementById('updateDeptCode');
            const updateDeptName = document.getElementById('updateDeptName');
            const updateDeptEmpNo = document.getElementById('updateDeptEmpNo');
            const updateDeptAoEmpNo = document.getElementById('updateDeptAoEmpNo');
            const updateDeptDesignation = document.getElementById('updateDeptDesignation');
            const updateParentDeptId = document.getElementById('updateParentDeptId');
            const updateRouteTemplate = @json(route('dashboard.records-manager.departments.update', ['department' => '__DEPT_ID__']));

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

            if (openButton) {
                openButton.addEventListener('click', function () {
                    openCreateModal();
                });
            }

            document.querySelectorAll('.open-update-department-modal').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!updateForm) {
                        return;
                    }

                    const departmentId = button.dataset.deptId || '';
                    updateForm.action = updateRouteTemplate.replace('__DEPT_ID__', departmentId);

                    if (updateDepartmentId) updateDepartmentId.value = departmentId;
                    if (updateDeptCode) updateDeptCode.value = button.dataset.deptCode || '';
                    if (updateDeptName) updateDeptName.value = button.dataset.deptName || '';
                    if (updateDeptEmpNo) updateDeptEmpNo.value = button.dataset.empNo || '';
                    if (updateDeptAoEmpNo) updateDeptAoEmpNo.value = button.dataset.aoEmpNo || '';
                    if (updateDeptDesignation) updateDeptDesignation.value = button.dataset.designation || '';
                    if (updateParentDeptId) updateParentDeptId.value = button.dataset.parentDeptId || '';

                    openUpdateModal();
                });
            });

            @if (old('create_department_form') === '1' && $errors->any())
            Swal.fire({
                icon: 'error',
                title: 'Unable to save changes',
                text: @json(implode("\n", $errors->all())),
                confirmButtonColor: '#ea580c',
            }).then(function () {
                openCreateModal();
            });
            @elseif (old('update_department_form') === '1' && $errors->any())
            if (updateForm) {
                updateForm.action = updateRouteTemplate.replace('__DEPT_ID__', @json((string) old('update_department_id', '')));
            }
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
    </script>
@endsection
