@extends('dashboards.layout', [
    'title' => 'Employees',
    'subtitle' => 'Overview of all employees across departments.',
])

@section('content')
    <div class="plantilla-stats">
        <div class="stat-tile">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
                <div class="stat-label">Total Employees</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-id-badge"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['permanent']) }}</div>
                <div class="stat-label">Permanent</div>
            </div>
        </div>
        <div class="stat-tile stat-vacant">
            <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['co_terminus']) }}</div>
                <div class="stat-label">Co-Terminus</div>
            </div>
        </div>
        <div class="stat-tile stat-promo">
            <div class="stat-icon"><i class="fas fa-file-contract"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['job_orders']) }}</div>
                <div class="stat-label">Job Orders</div>
            </div>
        </div>
        <div class="stat-tile stat-info">
            <div class="stat-icon"><i class="fas fa-file-signature"></i></div>
            <div>
                <div class="stat-value">{{ number_format($stats['contractual']) }}</div>
                <div class="stat-label">Contractual</div>
            </div>
        </div>
    </div>

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$employees" title="Employees" subtitle="Overview of all employees across departments.">
        <x-slot:filters>
            <form method="GET" action="{{ route('mayor.employees') }}" style="display:flex; flex-direction:column; gap:0.75rem; width:100%;">
                <div class="hris-search-input-group" style="max-width:520px;">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, email, or employee number" class="hris-search-input" aria-label="Search employees">
                    <button type="submit" class="hris-search-button" title="Search">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                    </button>
                </div>

                <div class="plantilla-filter-form">
                    <select name="employee_type" class="hris-filter-select">
                        <option value="">All employee types</option>
                        @foreach ($employeeTypes as $type)
                            <option value="{{ $type }}" @selected($employeeTypeFilter === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    <select name="department" class="hris-filter-select">
                        <option value="">All departments</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->Dept_id }}" @selected((string) $departmentFilter === (string) $department->Dept_id)>
                                {{ $department->Dept_name }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-filter"></i> Apply</button>
                    @if($search !== '' || $employeeTypeFilter !== '' || $departmentFilter !== '')
                        <a href="{{ route('mayor.employees') }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
                    @endif
                </div>
            </form>
        </x-slot:filters>

        @if ($employees->count())
            <table id="employeeTable" class="hris-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th>Date Hired</th>
                        <th>Email</th>
                        <th>Agency Employee No.</th>
                        <th>Access Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($employees as $employee)
                        @php
                            $departmentName = optional($departments->firstWhere('Dept_id', $employee->Dept_id))->Dept_name ?? 'Not assigned';
                            $empName = $employee->last_name ? "{$employee->last_name}, {$employee->first_name}" : $employee->name;
                            $empInitials = mb_strtoupper(mb_substr($employee->first_name ?: $employee->name, 0, 1).mb_substr($employee->last_name ?: '', 0, 1));
                            $empStatusKey = strtolower((string) ($employee->Status ?: 'unset'));
                        @endphp
                        <tr>
                            <td>
                                <div class="incumbent-cell">
                                    <span class="avatar-sm">{{ $empInitials ?: '?' }}</span>
                                    <span>{{ $empName ?: '-' }}</span>
                                </div>
                            </td>
                            <td>{{ $employee->designation ?: '-' }}</td>
                            <td>{{ $departmentName }}</td>
                            <td>{{ optional($employee->date_hired)->format('Y-m-d') ?: '-' }}</td>
                            <td>{{ $employee->email }}</td>
                            <td>{{ $employee->EmpNo ?: '-' }}</td>
                            <td>{{ ucwords((string) $employee->access_level) }}</td>
                            <td><span class="status-chip {{ $empStatusKey }}">{{ $employee->Status ?: 'Unset' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No employee records found.</p>
        @endif
    </x-hris.table-layout>
@endsection

@section('page_scripts')
    <script>
        // Initialize DataTables (guard to avoid reinitialization)
        document.addEventListener('DOMContentLoaded', function () {
            if (window.jQuery && typeof jQuery().DataTable === 'function' && $('#employeeTable').length) {
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
