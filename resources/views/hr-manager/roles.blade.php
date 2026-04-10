@extends('dashboards.layout', [
    'title' => 'User Roles & Access',
    'subtitle' => 'Manage role assignments and permission controls.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="roles">
        <div class="hrm-form-grid">
            <form class="hrm-form-card" method="POST" action="#">
                @csrf
                <h4>Create Role</h4>
                <label>Role Name
                    <input type="text" name="role_name" placeholder="e.g., Compliance Officer">
                </label>
                <label>Permissions
                    <textarea name="permissions" rows="4" placeholder="Comma-separated permissions"></textarea>
                </label>
                <button type="button" class="hrm-btn hrm-alert-success">Save Role</button>
            </form>

            <form class="hrm-form-card" method="POST" action="#">
                @csrf
                <h4>Adjust Permissions</h4>
                <label>Existing Role
                    <select name="existing_role">
                        @foreach($availableRoles as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Permission Rules
                    <textarea name="permission_rules" rows="4" placeholder="Define updated permission rules"></textarea>
                </label>
                <button type="button" class="hrm-btn hrm-alert-success">Update Permissions</button>
            </form>
        </div>

        <div class="hrm-table-wrap">
            <table class="hrm-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Current Role</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->Dept_name }}</td>
                            <td>{{ strtoupper((string) $user->access_level) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
