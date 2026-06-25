{{--
    Global Sidebar — self-contained, role-aware, icon-consistent.
    ─────────────────────────────────────────────────────────────
    To add a new sidebar item:
      1. Add its icon to $icons below (if new).
      2. Append ['label', 'icon', 'route', ...] to the role(s) in $menus.
      That's it — no other files need editing.

    Optional keys on link items:
      'permission' => 'methodName'   auth()->user()->methodName() must return true
      'badge'      => 'key'          renders a live count badge (resolved in $badges)
      'active'     => ['pattern*']   route patterns for active state; defaults to [route]
--}}
@php
    // ── Normalise role (same logic as EnsureRole middleware) ──
    $activeRole = strtolower(trim(str_replace(['_', '-'], ' ', (string) (auth()->user()->access_level ?? ''))));

    // ══════════════════════════════════════════════════════════
    //  ICON MAP — change an icon here and it updates everywhere
    // ══════════════════════════════════════════════════════════
    $icons = [
        // Navigation
        'dashboard'           => 'fas fa-home fa-fw',
        'snapshot'            => 'fas fa-tachometer-alt fa-fw',

        // Self-Service
        'pds'                 => 'fas fa-id-card fa-fw',
        'eta'                 => 'fas fa-plane-departure fa-fw',
        'locator'             => 'fas fa-map-marker-alt fa-fw',
        'documents'           => 'fas fa-file-alt fa-fw',

        // Leave
        'leave'               => 'fas fa-calendar-check fa-fw',
        'leave_balance'       => 'fas fa-scale-balanced fa-fw',
        'leave_credits'       => 'fas fa-coins fa-fw',
        'approved_leaves'     => 'fas fa-calendar-check fa-fw',
        'cancel_leave'        => 'fas fa-calendar-xmark fa-fw',
        'leave_approvals'     => 'fas fa-clipboard-check fa-fw',
        'leave_integration'   => 'fas fa-calendar-alt fa-fw',

        // Department / Requests
        'pending_requests'    => 'fas fa-hourglass-half fa-fw',
        'approved_requests'   => 'fas fa-check-circle fa-fw',
        'statistics'          => 'fas fa-chart-pie fa-fw',
        'travel_order'        => 'fas fa-route fa-fw',
        'travel_approval'     => 'fas fa-plane-circle-check fa-fw',
        'office_order'        => 'fas fa-file-signature fa-fw',
        'filed_travel'        => 'fas fa-folder-open fa-fw',
        'filed_office'        => 'fas fa-archive fa-fw',
        'oic'                 => 'fas fa-user-clock fa-fw',
        'monitoring_matrix'   => 'fas fa-table-list fa-fw',

        // Records & HR
        'records'             => 'fas fa-database fa-fw',
        'frontdesk'           => 'fas fa-concierge-bell fa-fw',
        'employees'           => 'fas fa-users fa-fw',
        'departments'         => 'fas fa-building fa-fw',
        'access'              => 'fas fa-key fa-fw',

        // Analytics & Reports
        'analytics'           => 'fas fa-chart-line fa-fw',
        'reports'             => 'fas fa-chart-bar fa-fw',
        'audit'               => 'fas fa-shield-halved fa-fw',

        // Payroll
        'payroll_create'      => 'fas fa-plus-circle fa-fw',
        'payroll_view'        => 'fas fa-eye fa-fw',
        'payroll_runs'        => 'fas fa-receipt fa-fw',
        'attendance'          => 'fas fa-clock fa-fw',
        'attendance_import'   => 'fas fa-file-import fa-fw',
        'work_schedule'       => 'fas fa-user-clock fa-fw',
        'plantilla'           => 'fas fa-building fa-fw',
        'salary_matrix'       => 'fas fa-table fa-fw',
        'earnings'            => 'fas fa-coins fa-fw',
        'deductions'          => 'fas fa-minus-circle fa-fw',
        'exceptions'          => 'fas fa-exclamation-triangle fa-fw',
        'approvals'           => 'fas fa-check-circle fa-fw',
        'payslips'            => 'fas fa-file-invoice-dollar fa-fw',

        // Administration
        'roles'               => 'fas fa-user-shield fa-fw',
        'settings'            => 'fas fa-cog fa-fw',
        'policies'            => 'fas fa-gavel fa-fw',
        'events'              => 'fas fa-calendar-days fa-fw',

        // Auth
        'logout'              => 'fas fa-sign-out-alt fa-fw',
    ];

    // ══════════════════════════════════════════════════════════
    //  ROLE MENUS — each role's ordered list of sidebar items
    // ══════════════════════════════════════════════════════════
    $menus = [

        // ─── Employee ──────────────────────────────────────
        'employee' => [
            ['label' => 'Dashboard',        'icon' => 'dashboard',  'route' => 'dashboard',                     'active' => ['dashboard']],

            ['section' => 'Self-Service'],
            ['label' => 'PDS',              'icon' => 'pds',        'route' => 'dashboard.employee.pds',        'active' => ['dashboard.employee.pds']],
            ['label' => 'ETA',              'icon' => 'eta',        'route' => 'dashboard.employee.eta',        'active' => ['dashboard.employee.eta']],
            ['label' => 'Locator',          'icon' => 'locator',    'route' => 'dashboard.employee.locator',    'active' => ['dashboard.employee.locator']],
            ['label' => 'Leave Requests',   'icon' => 'leave',      'route' => 'employee.leave.management',     'active' => ['employee.leave.management']],
            ['label' => 'Request Documents','icon' => 'documents',  'route' => 'dashboard.employee.request-documents',       'active' => ['document-requests.*', 'dashboard.employee.request-documents']],

            ['section' => 'Records'],
            ['label' => 'Payslips',         'icon' => 'payslips',   'route' => 'dashboard.employee.payslips',   'active' => ['dashboard.employee.payslips']],
            ['label' => 'My DTR',           'icon' => 'attendance', 'route' => 'attendance.dtr',                'active' => ['attendance.dtr', 'attendance.dtr.download']],
        ],

        // ─── Department Head ───────────────────────────────
        'department head' => [
            ['label' => 'Dashboard',          'icon' => 'dashboard',          'route' => 'department-head.index',             'active' => ['department-head.index']],

            ['section' => 'Self-Service'],
            ['label' => 'PDS',                'icon' => 'pds',               'route' => 'dashboard.employee.pds',            'active' => ['dashboard.employee.pds']],
            ['label' => 'Leave Management',   'icon' => 'leave',             'route' => 'employee.leave.management',         'active' => ['employee.leave.management']],

            ['section' => 'Department Management'],
            ['label' => 'Pending Requests',   'icon' => 'pending_requests',  'route' => 'department-head.pending-requests',  'active' => ['department-head.pending-requests'], 'badge' => 'pending_requests_dept'],
            ['label' => 'Approved Requests',  'icon' => 'approved_requests', 'route' => 'department-head.approved-requests', 'active' => ['department-head.approved-requests']],
            ['label' => 'Statistics',         'icon' => 'statistics',        'route' => 'department-head.statistics',        'active' => ['department-head.statistics']],
            ['label' => 'Travel Order',       'icon' => 'travel_order',      'route' => 'department-head.travel-orders',     'active' => ['department-head.travel-orders']],
            ['label' => 'Office Order',       'icon' => 'office_order',      'route' => 'department-head.office-orders',     'active' => ['department-head.office-orders']],
            ['label' => 'Filed T.O.s',        'icon' => 'filed_travel',      'route' => 'department-head.filed-travel-orders',  'active' => ['department-head.filed-travel-orders']],
            ['label' => 'Filed Office Order', 'icon' => 'filed_office',      'route' => 'department-head.filed-office-orders',  'active' => ['department-head.filed-office-orders']],
            ['label' => 'OIC Assignments',    'icon' => 'oic',               'route' => 'department-head.oic-assignments.index', 'active' => ['department-head.oic-assignments.index']],

            ['section' => 'Attendance'],
            ['label' => 'DTR Records',        'icon' => 'attendance',        'route' => 'attendance.dtr',                       'active' => ['attendance.dtr', 'attendance.dtr.download']],
        ],

        // ─── Administrative Officer ────────────────────────
        'administrative officer' => [
            ['label' => 'Dashboard',          'icon' => 'dashboard',          'route' => 'admin-officer.index',              'active' => ['admin-officer.index']],

            ['section' => 'Self-Service'],
            ['label' => 'PDS',               'icon' => 'pds',       'route' => 'dashboard.employee.pds',               'active' => ['dashboard.employee.pds']],
            ['label' => 'ETA',               'icon' => 'eta',       'route' => 'dashboard.employee.eta',               'active' => ['dashboard.employee.eta']],
            ['label' => 'Locator',           'icon' => 'locator',   'route' => 'dashboard.employee.locator',           'active' => ['dashboard.employee.locator']],
            ['label' => 'Leave Requests',    'icon' => 'leave',     'route' => 'employee.leave.management',            'active' => ['employee.leave.management']],
            ['label' => 'Request Documents', 'icon' => 'documents', 'route' => 'dashboard.employee.request-documents', 'active' => ['document-requests.*', 'dashboard.employee.request-documents']],
            ['label' => 'Payslips',          'icon' => 'payslips',  'route' => 'dashboard.employee.payslips',          'active' => ['dashboard.employee.payslips']],

            ['section' => 'Department Management'],
            ['label' => 'Pending Requests',   'icon' => 'pending_requests',  'route' => 'admin-officer.pending-requests',   'active' => ['admin-officer.pending-requests'], 'badge' => 'pending_requests_dept'],
            ['label' => 'Approved Requests',  'icon' => 'approved_requests', 'route' => 'admin-officer.approved-requests',  'active' => ['admin-officer.approved-requests']],
            ['label' => 'Statistics',         'icon' => 'statistics',        'route' => 'admin-officer.statistics',         'active' => ['admin-officer.statistics']],
            ['label' => 'Travel Order',       'icon' => 'travel_order',      'route' => 'admin-officer.travel-orders',      'active' => ['admin-officer.travel-orders']],
            ['label' => 'Office Order',       'icon' => 'office_order',      'route' => 'admin-officer.office-orders',      'active' => ['admin-officer.office-orders']],
            ['label' => 'Filed T.O.s',        'icon' => 'filed_travel',      'route' => 'admin-officer.filed-travel-orders',   'active' => ['admin-officer.filed-travel-orders']],
            ['label' => 'Filed Office Order', 'icon' => 'filed_office',        'route' => 'admin-officer.filed-office-orders',   'active' => ['admin-officer.filed-office-orders']],
            ['label' => 'OIC Assignments',    'icon' => 'oic',               'route' => 'department-head.oic-assignments.index', 'active' => ['department-head.oic-assignments.index']],
            ['label' => 'Monitoring Matrix',  'icon' => 'monitoring_matrix', 'route' => 'admin-officer.monitoring-matrix',      'active' => ['admin-officer.monitoring-matrix']],

            ['section' => 'Attendance'],
            ['label' => 'DTR Records',        'icon' => 'attendance',        'route' => 'attendance.dtr',                   'active' => ['attendance.dtr', 'attendance.dtr.download']],
        ],

        // ─── HR Manager ───────────────────────────────────
        'hr manager' => [
            ['section' => 'Dashboard'],
            ['label' => 'Charts &amp; Analytics', 'icon' => 'analytics', 'route' => 'hr-manager.dashboard', 'active' => ['hr-manager.dashboard'], 'badge' => 'hr_alerts'],

            ['section' => 'Operations'],
            ['label' => 'Records Management', 'icon' => 'records',      'route' => 'hr-manager.records',              'active' => ['hr-manager.records']],
            ['label' => 'Leave Management',   'icon' => 'leave',        'route' => 'hr-manager.leave',                'active' => ['hr-manager.leave']],
            ['label' => 'Front Desk',         'icon' => 'frontdesk',    'route' => 'hr-manager.frontdesk',            'active' => ['hr-manager.frontdesk']],
            ['label' => 'Payroll Overview',   'icon' => 'payroll_runs', 'route' => 'hr-manager.payroll.overview',     'active' => ['hr-manager.payroll.overview*']],

            ['section' => 'Attendance'],
            ['label' => 'Attendance Overview','icon' => 'statistics',        'route' => 'hr-manager.attendance.overview', 'active' => ['hr-manager.attendance.overview*']],
            ['label' => 'DTR Records',        'icon' => 'attendance',        'route' => 'attendance.dtr',                 'active' => ['attendance.dtr', 'attendance.dtr.download']],
            ['label' => 'Shift Templates',    'icon' => 'work_schedule',     'route' => 'attendance.shifts',                   'active' => ['attendance.shifts*']],
            ['label' => 'Shift Assignment',   'icon' => 'work_schedule',     'route' => 'attendance.schedules',                'active' => ['attendance.schedules*']],
            ['label' => 'Shift Schedule',     'icon' => 'work_schedule',     'route' => 'attendance.shift-schedule.index',     'active' => ['attendance.shift-schedule*']],
            ['label' => 'Import Logs',        'icon' => 'attendance_import', 'route' => 'hr-manager.attendance.import',        'active' => ['hr-manager.attendance.import*']],

            ['section' => 'Reports'],
            ['label' => 'HR Reports',         'icon' => 'reports',   'route' => 'hr-manager.reports',     'active' => ['hr-manager.reports']],
            ['label' => 'Leave Ledger',       'icon' => 'audit',     'route' => 'hr-manager.leave-ledger','active' => ['hr-manager.leave-ledger']],
            ['label' => 'Audit Logs',         'icon' => 'audit',     'route' => 'hr-manager.audit',       'active' => ['hr-manager.audit']],

            ['section' => 'Administration'],
            ['label' => 'User Roles &amp; Access', 'icon' => 'roles',    'route' => 'hr-manager.roles',    'active' => ['hr-manager.roles']],
            ['label' => 'System Settings',    'icon' => 'settings',  'route' => 'hr-manager.settings',  'active' => ['hr-manager.settings']],

            ['section' => 'Self-Service'],
            ['label' => 'PDS',               'icon' => 'pds',       'route' => 'dashboard.employee.pds',    'active' => ['dashboard.employee.pds']],
            ['label' => 'Leave Management',  'icon' => 'leave',     'route' => 'employee.leave.management', 'active' => ['employee.leave.management']],
        ],

        // ─── Leave Manager ─────────────────────────────────
        'leave manager' => [
            ['label' => 'Leave Manager',       'icon' => 'dashboard',       'route' => 'dashboard',                       'active' => ['dashboard']],
            ['label' => 'Manage Leave Balance','icon' => 'leave_balance',   'route' => 'leave-manager.manage-balance',    'active' => ['leave-manager.manage-balance']],
            ['label' => 'Manage Leave Credits','icon' => 'leave_credits',   'route' => 'leave-manager.manage-credits',    'active' => ['leave-manager.manage-credits']],
            ['label' => 'Leave Ledger',        'icon' => 'audit',           'route' => 'leave-manager.leave-ledger',      'active' => ['leave-manager.leave-ledger']],
            ['label' => 'Approved Leaves',    'icon' => 'approved_leaves',  'route' => 'leave-manager.approved-leaves',   'active' => ['leave-manager.approved-leaves']],
            ['label' => 'Employee Cancellation Requests', 'icon' => 'leave', 'route' => 'leave-manager.employee-cancellation-requests', 'active' => ['leave-manager.employee-cancellation-requests'], 'badge' => 'pending_employee_cancellation_requests'],
        ],

        // ─── Payroll Manager ──────────────────────────────
        'payroll manager' => [
            ['label' => 'Dashboard',           'icon' => 'dashboard',       'route' => 'payroll.dashboard',           'active' => ['payroll.dashboard']],

            ['section' => 'Pay Processing'],
            ['label' => 'Payroll Runs',        'icon' => 'payroll_runs',    'route' => 'payroll.runs.index',          'active' => ['payroll.runs.*']],

            ['section' => 'Compensation'],
            ['label' => 'Plantilla &amp; Salary',  'icon' => 'plantilla',      'route' => 'payroll.plantilla.index',     'active' => ['payroll.plantilla.*']],
            ['label' => 'Salary Matrix',       'icon' => 'salary_matrix',   'route' => 'payroll.salary-matrix.index', 'active' => ['payroll.salary-matrix.*']],
            ['label' => 'Earnings (Allowances)', 'icon' => 'earnings',      'route' => 'payroll.earnings.index',      'active' => ['payroll.earnings.*']],
            ['label' => 'Deductions',          'icon' => 'deductions',      'route' => 'payroll.deductions.index',    'active' => ['payroll.deductions.*']],

            ['section' => 'Integration'],
            ['label' => 'Leave Integration',   'icon' => 'leave_integration', 'route' => 'payroll.leave-integration.index', 'active' => ['payroll.leave-integration.*']],

            ['section' => 'Review &amp; Finalize'],
            ['label' => 'Exceptions',          'icon' => 'exceptions',      'route' => 'payroll.exceptions.index',    'active' => ['payroll.exceptions.*']],
            ['label' => 'Approvals',           'icon' => 'approvals',       'route' => 'payroll.approvals.index',     'active' => ['payroll.approvals.*']],
            ['label' => 'Payslips',            'icon' => 'payslips',        'route' => 'payroll.payslips.index',      'active' => ['payroll.payslips.*']],

            ['section' => 'Monitoring'],
            ['label' => 'Reports',             'icon' => 'reports',         'route' => 'payroll.reports.index',       'active' => ['payroll.reports.*']],
            ['label' => 'Audit Logs',          'icon' => 'audit',           'route' => 'payroll.audit-logs.index',    'active' => ['payroll.audit-logs.*']],

            ['section' => 'System'],
            ['label' => 'Settings',            'icon' => 'settings',        'route' => 'payroll.settings.index',      'active' => ['payroll.settings.*']],
        ],

        // ─── Records Manager ──────────────────────────────
        'records manager' => [
            ['label' => 'Dashboard',             'icon' => 'dashboard',   'route' => 'dashboard.records-manager',             'active' => ['dashboard.records-manager']],
            ['label' => 'Employee Management',   'icon' => 'employees',   'route' => 'dashboard.records-manager.employees',   'active' => ['dashboard.records-manager.employees']],
            ['label' => 'Department Management', 'icon' => 'departments', 'route' => 'dashboard.records-manager.departments', 'active' => ['dashboard.records-manager.departments']],
            ['label' => 'Access Management',     'icon' => 'access',      'route' => 'dashboard.records-manager.access',      'active' => ['dashboard.records-manager.access']],

            ['section' => 'Attendance'],
            ['label' => 'DTR Records', 'icon' => 'attendance',        'route' => 'attendance.dtr',               'active' => ['attendance.dtr', 'attendance.dtr.download']],
            ['label' => 'Import Logs', 'icon' => 'attendance_import', 'route' => 'hr-manager.attendance.import', 'active' => ['hr-manager.attendance.import*']],
        ],

        // ─── Time Keeper ──────────────────────────────────
        'time keeper' => [
            ['label' => 'Dashboard',   'icon' => 'dashboard', 'route' => 'dashboard', 'active' => ['dashboard']],

            ['section' => 'Attendance'],
            ['label' => 'DTR Records',      'icon' => 'attendance',        'route' => 'attendance.dtr',               'active' => ['attendance.dtr', 'attendance.dtr.download']],
            ['label' => 'Shift Templates',  'icon' => 'work_schedule',     'route' => 'attendance.shifts',                 'active' => ['attendance.shifts*']],
            ['label' => 'Shift Assignment', 'icon' => 'work_schedule',     'route' => 'attendance.schedules',              'active' => ['attendance.schedules*']],
            ['label' => 'Shift Schedule',   'icon' => 'work_schedule',     'route' => 'attendance.shift-schedule.index',   'active' => ['attendance.shift-schedule*']],
            ['label' => 'Import Logs',      'icon' => 'attendance_import', 'route' => 'hr-manager.attendance.import',      'active' => ['hr-manager.attendance.import*']],
        ],

        // ─── Front Desk ───────────────────────────────────
        'front desk' => [
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'front-desk.index', 'active' => ['front-desk.*']],
            ['label' => 'Pending Requests', 'icon' => 'pending_requests', 'route' => 'employee.pending-requests', 'active' => ['employee.pending-requests'], 'badge' => 'pending_document_requests'],
            ['label' => 'Approved Requests', 'icon' => 'approved_requests', 'route' => 'employee.approved-requests', 'active' => ['employee.approved-requests'], 'badge' => 'approved_document_requests'],
            ['label' => 'Document Settings', 'icon' => 'settings', 'route' => 'employee.document-settings', 'active' => ['employee.document-settings']],
        ],

        // ─── Mayor ────────────────────────────────────────
        'mayor' => [
            ['section' => 'Dashboard'],
            ['label' => 'Executive Snapshot',      'icon' => 'snapshot',         'route' => 'mayor.dashboard',              'active' => ['mayor.dashboard']],

            ['section' => 'Management'],
            ['label' => 'Leave Approvals',         'icon' => 'leave_approvals',  'route' => 'mayor.approvals',              'active' => ['mayor.approvals'],              'badge' => 'pending_leaves_mayor'],
            ['label' => 'Travel Order Approval',   'icon' => 'travel_approval',  'route' => 'mayor.travel-order-approvals', 'active' => ['mayor.travel-order-approvals'], 'badge' => 'pending_travel_orders'],
            ['label' => 'Reports',                 'icon' => 'reports',          'route' => 'mayor.reports',                'active' => ['mayor.reports']],
            ['label' => 'Policies',                'icon' => 'policies',         'route' => 'mayor.policies',               'active' => ['mayor.policies']],
            ['label' => 'Employees',               'icon' => 'employees',        'route' => 'mayor.employees',              'active' => ['mayor.employees']],
            ['label' => 'Events',                  'icon' => 'events',           'route' => 'mayor.events',                 'active' => ['mayor.events']],

            ['section' => 'System'],
            ['label' => 'Settings',                'icon' => 'settings',         'route' => 'mayor.settings',               'active' => ['mayor.settings']],
        ],
    ];

    // ══════════════════════════════════════════════════════════
    //  BADGE RESOLVERS — centralized, computed once per request
    // ══════════════════════════════════════════════════════════
    //  Each key matches a 'badge' value on a menu item above.
    //  Closures are only called when the current role's menu
    //  actually references the badge, keeping queries minimal.
    // ══════════════════════════════════════════════════════════
    $badgeResolvers = [
        'pending_leaves_mayor' => fn () => \App\Models\LeaveRequest::where('status', 'pending')
            ->whereIn('user_id', \App\Models\User::whereRaw(
                "LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) IN ('department head', 'hr manager')"
            )->pluck('id'))
            ->count(),

        'pending_travel_orders' => fn () => \Illuminate\Support\Facades\DB::table('travel_orders')
            ->where('status', 'Pending')
            ->count(),

        // Add new badges here — they'll work for any role that references them:
        // 'pending_documents' => fn () => \App\Models\DocumentRequest::where('status', 'pending')->count(),
        'pending_requests_dept' => function () {
            $user = auth()->user();
            if (!$user || empty($user->Dept_id)) return 0;
            $deptId = $user->Dept_id;

            $employeeIds = \App\Models\User::where('Dept_id', $deptId)->pluck('id')->toArray();
            if (empty($employeeIds)) return 0;

            // Exclude leave requests filed by Department Heads (Mayor handles those)
            $leave = \App\Models\LeaveRequest::whereIn('user_id', $employeeIds)
                ->where('status', 'pending')
                ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
                ->count();
            $eta = \App\Models\Eta::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();
            $locator = \App\Models\Locator::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();

            return $leave + $eta + $locator;
        },
        'pending_employee_cancellation_requests' => fn () => \App\Models\LeaveRequest::where('status', 'approved')
            ->where('cancellation_status', 'Pending Cancellation')
            ->count(),
        'pending_document_requests' => fn () => \App\Models\DocumentRequest::where('status', 'Requested')->count(),
        'approved_document_requests' => fn () => \App\Models\DocumentRequest::whereIn('status', ['Accepted', 'Completed'])->count(),
        'hr_alerts' => function () {
            $staleDays = 3;
            $count = 0;
            if (\Illuminate\Support\Facades\Schema::hasTable('leave_requests')) {
                $count += \Illuminate\Support\Facades\DB::table('leave_requests')
                    ->whereRaw('LOWER(status) = ?', ['pending'])
                    ->where('created_at', '<', now()->subDays($staleDays))
                    ->count();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('travel_orders')) {
                $count += \Illuminate\Support\Facades\DB::table('travel_orders')
                    ->whereRaw('LOWER(status) = ?', ['pending'])
                    ->where('created_at', '<', now()->subDays($staleDays))
                    ->count();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('document_requests')) {
                $count += \Illuminate\Support\Facades\DB::table('document_requests')
                    ->whereRaw('LOWER(status) = ?', ['requested'])
                    ->where('requested_on', '<', now()->subDays($staleDays))
                    ->count();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('payroll_exceptions')) {
                $count += \Illuminate\Support\Facades\DB::table('payroll_exceptions')
                    ->where('resolved_flag', false)
                    ->count();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('payroll_runs')) {
                $openRun = \Illuminate\Support\Facades\DB::table('payroll_runs')
                    ->where('status', 'draft')
                    ->whereNull('locked_at')
                    ->exists();
                if ($openRun) $count++;
            }
            return $count;
        },
    ];

    // ── OIC: merge Self-Service (employee) + Department Management (OIC role) ──
    $oicRole = null;
    if (!in_array($activeRole, ['department head', 'administrative officer'])) {
        $today = now()->toDateString();
        $oicAssignment = \App\Models\OicAssignment::where('user_id', auth()->id())
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->first();
        if ($oicAssignment) {
            $oicRole = $oicAssignment->role; // 'department head' | 'administrative officer'
        }
    }

    // ── Resolve items for current role ──
    if ($oicRole) {
        $oicRoleMenu  = $menus[$oicRole] ?? [];
        $employeeMenu = $menus[$activeRole] ?? [];

        // Dashboard from the OIC role (first non-section item)
        $oicDashboard = [];
        foreach ($oicRoleMenu as $_item) {
            if (!isset($_item['section'])) { $oicDashboard[] = $_item; break; }
        }

        // All employee menu items except the first link (their own employee dashboard)
        $employeeBody = [];
        $_skipped = false;
        foreach ($employeeMenu as $_item) {
            if (!$_skipped && !isset($_item['section'])) { $_skipped = true; continue; }
            $employeeBody[] = $_item;
        }

        // Only the "Department Management" section from the OIC role menu
        // Stop before the next section (Attendance) to avoid DTR duplication
        $deptMgmtItems = [];
        $_inDeptMgmt = false;
        foreach ($oicRoleMenu as $_item) {
            if (isset($_item['section'])) {
                if ($_item['section'] === 'Department Management') { $_inDeptMgmt = true; }
                elseif ($_inDeptMgmt) { break; }
            }
            if ($_inDeptMgmt) { $deptMgmtItems[] = $_item; }
        }

        $items = array_merge($oicDashboard, $employeeBody, $deptMgmtItems);
    } else {
        $items = $menus[$activeRole] ?? [];
    }

    // ── Only resolve badges actually used by the current role's items ──
    $neededBadgeKeys = collect($items)->pluck('badge')->filter()->unique();
    $badges = [];
    foreach ($neededBadgeKeys as $key) {
        if (isset($badgeResolvers[$key])) {
            $badges[$key] = $badgeResolvers[$key]();
        }
    }
@endphp

<aside class="global-sidebar" aria-label="Primary navigation">
    <div class="sidebar-brand">
        <img src="{{ asset('assets/login/mbs.jpg') }}" alt="HRIS" class="sidebar-logo">
        <div>
            <strong>HRIS Portal</strong>
            <p>Human Resource Information</p>
        </div>
    </div>

    <nav class="sidebar-nav">
        @foreach ($items as $item)
            {{-- Section header --}}
            @if (isset($item['section']))
                <span class="sidebar-meta">{!! $item['section'] !!}</span>
                @continue
            @endif

            {{-- Permission gate — skip when user method returns false --}}
            @if (isset($item['permission']) && !auth()->user()->{$item['permission']}())
                @continue
            @endif

            @php
                $iconClass      = $icons[$item['icon'] ?? ''] ?? '';
                $activePatterns = $item['active'] ?? [$item['route']];
                $isActive       = request()->routeIs(...$activePatterns);
                $badgeKey       = $item['badge'] ?? null;
                $badgeCount     = $badgeKey ? ($badges[$badgeKey] ?? 0) : 0;
            @endphp

            <a href="{{ route($item['route']) }}"
               class="sidebar-link @if($isActive) active @endif">
                @if ($iconClass)
                    <i class="{{ $iconClass }}"></i>
                @endif
                {!! $item['label'] !!}
                @if ($badgeCount > 0)
                    <span class="sidebar-badge" data-badge-key="{{ $badgeKey }}">{{ $badgeCount }}</span>
                @endif
            </a>
        @endforeach

        @if (auth()->check())
            <span class="sidebar-meta">{{ auth()->user()->name }}</span>
            <span class="sidebar-meta muted">{{ auth()->user()->email }}</span>
        @endif
    </nav>

    @auth
        <a href="{{ route('user.change-password.form') }}" class="sidebar-link @if(request()->routeIs('user.change-password.form')) active @endif" style="margin-bottom: 4px;">
            <i class="fas fa-lock fa-fw"></i> Change Password
        </a>
        <form method="POST" action="{{ route('logout') }}" class="sidebar-logout">
            @csrf
            <button type="submit" class="sidebar-logout-btn">
                <i class="{{ $icons['logout'] }}"></i> Logout
            </button>
        </form>
    @else
        <a href="{{ route('login') }}" class="sidebar-logout-btn guest-login">Login</a>
    @endauth
</aside>
