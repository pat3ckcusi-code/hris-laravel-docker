@extends('dashboards.layout', [
    'title' => 'Records Management',
    'subtitle' => 'Employee profile registry with compliance and status tracking.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="records" data-url="{{ $recordsDataUrl }}" data-action-url="{{ route('hr-manager.records.action', ['user' => '__ID__']) }}" data-csrf="{{ csrf_token() }}" data-pagination='@json($recordsPagination)'>
        <div class="hrm-toolbar">
            <input type="text" id="recordsSearch" placeholder="Search by EmpNo, name, or position" value="{{ $recordsFilters['search'] }}">
            <select id="recordsDepartment">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}" @selected($recordsFilters['department'] == (string) $department->Dept_id)>{{ $department->Dept_name }}</option>
                @endforeach
            </select>
            <select id="recordsStatus">
                <option value="">All Status</option>
                <option value="Active" @selected($recordsFilters['status'] === 'Active')>Active</option>
                <option value="Inactive" @selected($recordsFilters['status'] === 'Inactive')>Inactive</option>
                <option value="Separated" @selected($recordsFilters['status'] === 'Separated')>Separated</option>
            </select>
            <button class="hrm-btn" id="recordsFilterBtn" type="button">Apply Filter</button>
        </div>

        <div class="hrm-table-wrap">
            <table class="hrm-table" id="recordsTable">
                <thead>
                    <tr>
                        <th>EmpNo</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Employment Status</th>
                        <th>History</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($employees as $employee)
                        <tr data-id="{{ $employee['id'] }}">
                            <td>{{ $employee['emp_no'] }}</td>
                            <td>{{ $employee['name'] }}</td>
                            <td>{{ $employee['department'] }}</td>
                            <td>{{ $employee['position'] }}</td>
                            <td><span class="status-chip">{{ $employee['employment_status'] }}</span></td>
                            <td>{{ $employee['history'] }}</td>
                            <td>
                                <button class="hrm-btn-secondary hrm-record-edit" type="button">Edit</button>
                                <button class="hrm-btn-secondary hrm-record-update" type="button">Update</button>
                                <button class="hrm-btn-secondary hrm-record-compliance" type="button">Generate Compliance Report</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div id="recordsPagination" class="hrm-pagination"></div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
