@extends('dashboards.layout', [
    'title' => 'Employees',
    'subtitle' => 'Overview of all employees across departments.',
])

@section('page_head')
    @vite('resources/css/records_manager.css')
    @include('partials.table-styles')
@endsection

@section('content')
    <section class="records-manager-employees">
        <form method="GET" action="{{ route('mayor.employees') }}" class="filter-form">
            <div class="toolbar-filters">
                <label>
                    Search
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by name, email, or employee number"
                        value="{{ $search }}"
                    />
                </label>

                <label>
                    Status
                    <select name="status">
                        <option value="">All statuses</option>
                        <option value="Active" @selected($statusFilter === 'Active')>Active</option>
                        <option value="Inactive" @selected($statusFilter === 'Inactive')>Inactive</option>
                        <option value="Separated" @selected($statusFilter === 'Separated')>Separated</option>
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
                <a href="{{ route('mayor.employees') }}" class="toolbar-reset">Reset</a>
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
                        <th>Employee No.</th>
                        <th>Access Level</th>
                        <th>Status</th>
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination-wrap">
            {{ $employees->links() }}
        </div>
    </section>
@endsection

@section('page_scripts')
    <script>
        // Initialize DataTables (guard to avoid reinitialization)
        document.addEventListener('DOMContentLoaded', function () {
            if (window.jQuery && typeof jQuery().DataTable === 'function') {
                if (!$.fn.dataTable.isDataTable('#employeeTable')) {
                    $('#employeeTable').DataTable({
                        paging: false,
                        lengthChange: false,
                        searching: false,
                        info: false,
                        ordering: true,
                        language: {
                            paginate: { previous: 'Prev', next: 'Next' },
                            emptyTable: 'No employee records found.'
                        }
                    });
                }
            }
        });
    </script>
@endsection

