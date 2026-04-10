<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\FrontDeskController;
use App\Http\Controllers\DepartmentHeadController;
use App\Http\Controllers\HRManagerController;
use App\Http\Controllers\LeaveManagerController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\MayorController;
use App\Http\Controllers\Payroll\PayrollDashboardController;
use App\Http\Controllers\Payroll\PayrollRunController;
use App\Http\Controllers\Payroll\AttendanceController as PayrollAttendanceController;
use App\Http\Controllers\Payroll\PlantillaController;
use App\Http\Controllers\Payroll\EmployeeAssignmentController;
use App\Http\Controllers\Payroll\SalaryMatrixController;
use App\Http\Controllers\Payroll\EarningsController;
use App\Http\Controllers\Payroll\DeductionsController;
use App\Http\Controllers\Payroll\LeaveIntegrationController;
use App\Http\Controllers\Payroll\ExceptionsController;
use App\Http\Controllers\Payroll\ApprovalsController;
use App\Http\Controllers\Payroll\PayslipController;
use App\Http\Controllers\Payroll\ReportsController as PayrollReportsController;
use App\Http\Controllers\Payroll\AuditLogController as PayrollAuditLogController;
use App\Http\Controllers\Payroll\PayrollSettingsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Serve a favicon at /favicon.ico to avoid opaque/no-payload requests
Route::get('/favicon.ico', function () {
    $path = public_path('assets/login/mbs.jpg');
    if (file_exists($path)) {
        return response()->file($path, ['Content-Type' => 'image/jpeg']);
    }

    abort(404);
});

// Employee Leave Management
Route::middleware(['auth', 'deny.job.order'])->group(function () {
    Route::get('/employee/leave-management', [LeaveRequestController::class, 'index'])->name('employee.leave.management');
    Route::post('/employee/leave-management/apply', [LeaveRequestController::class, 'store'])->name('employee.leave.apply');
    Route::post('/employee/leave-management/{id}/approve', [LeaveRequestController::class, 'approve'])->name('employee.leave.approve');
    Route::get('/employee/leave-management/{id}', [LeaveRequestController::class, 'show'])->name('employee.leave.show');
    Route::get('/employee/leave-management/{id}/edit', [LeaveRequestController::class, 'edit'])->name('employee.leave.edit');
    Route::patch('/employee/leave-management/{id}/cancel', [LeaveRequestController::class, 'cancel'])->name('employee.leave.cancel');

    });

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login')->name('login.submit');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])
        ->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])
        ->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])
        ->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/force-password-change', [LoginController::class, 'showForcePasswordChange'])
        ->name('password.force.edit');
    Route::post('/force-password-change', [LoginController::class, 'updateForcePasswordChange'])
        ->name('password.force.update');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/employee/pds', [DashboardController::class, 'employeePds'])
        ->name('dashboard.employee.pds');

    // Employee Self-Service Modules
    Route::get('/dashboard/employee/eta-locator', [\App\Http\Controllers\Employee\EtaController::class, 'index'])
        ->name('dashboard.employee.eta');
    Route::post('/dashboard/employee/eta-locator', [\App\Http\Controllers\Employee\EtaController::class, 'store'])
        ->name('employee.eta.store');
    Route::get('/dashboard/employee/eta-locator/data', [\App\Http\Controllers\Employee\EtaController::class, 'data'])
        ->name('employee.eta.data');
    Route::get('/dashboard/employee/eta-locator/{eta}/print', [\App\Http\Controllers\Employee\EtaController::class, 'printSingle'])
        ->name('employee.eta.print.single');
    Route::post('/dashboard/employee/eta-locator/{eta}/cancel', [\App\Http\Controllers\Employee\EtaController::class, 'cancel'])
        ->name('employee.eta.cancel');
    Route::get('/dashboard/employee/leave/{leave}/print', [\App\Http\Controllers\LeaveRequestController::class, 'printSingle'])
        ->name('employee.leave.print.single');
    Route::get('/dashboard/employee/eta-locator/print', [\App\Http\Controllers\Employee\EtaController::class, 'print'])
        ->name('employee.eta.print');
    Route::get('/dashboard/employee/locator', [\App\Http\Controllers\Employee\LocatorController::class, 'index'])
        ->name('dashboard.employee.locator');
    Route::post('/dashboard/employee/locator', [\App\Http\Controllers\Employee\LocatorController::class, 'store'])
        ->name('employee.locator.store');
    Route::get('/dashboard/employee/locator/{locator}/edit', [\App\Http\Controllers\Employee\LocatorController::class, 'edit'])
        ->name('employee.locator.edit');
    Route::put('/dashboard/employee/locator/{locator}', [\App\Http\Controllers\Employee\LocatorController::class, 'update'])
        ->name('employee.locator.update');
    Route::get('/dashboard/employee/locator/data', [\App\Http\Controllers\Employee\LocatorController::class, 'data'])
        ->name('employee.locator.data');
    Route::get('/dashboard/employee/locator/{locator}/print', [\App\Http\Controllers\Employee\LocatorController::class, 'printSingle'])
        ->name('employee.locator.print.single');
    Route::get('/dashboard/employee/request-documents', [DocumentRequestController::class, 'index'])
        ->name('dashboard.employee.request-documents');
    Route::post('/document-requests', [DocumentRequestController::class, 'store'])
        ->name('document-requests.store');
    Route::get('/dashboard/employee/front-desk', [FrontDeskController::class, 'index'])
        ->name('front-desk.index');
    Route::get('/dashboard/employee/front-desk/requests', [FrontDeskController::class, 'fetchRequests'])
        ->name('front-desk.requests');
    Route::post('/dashboard/employee/front-desk/accept', [FrontDeskController::class, 'acceptRequest'])
        ->name('front-desk.accept');
    Route::post('/dashboard/employee/front-desk/reject', [FrontDeskController::class, 'rejectRequest'])
        ->name('front-desk.reject');
    Route::post('/dashboard/employee/front-desk/complete', [FrontDeskController::class, 'completeRequest'])
        ->name('front-desk.complete');
    Route::get('/dashboard/employee/front-desk/print/{id}', [FrontDeskController::class, 'printRequest'])
        ->name('front-desk.print-request');
    Route::post('/dashboard/employee/front-desk/update-status', [FrontDeskController::class, 'updateStatus'])
        ->name('front-desk.update-status');
    Route::post('/dashboard/employee/front-desk/print-report', [FrontDeskController::class, 'printReport'])
        ->name('front-desk.print-report');
    Route::post('/dashboard/employee/pds/save-draft', [DashboardController::class, 'savePdsDraft'])
        ->name('dashboard.employee.pds.save-draft');
    Route::get('/dashboard/employee/pds/export', [DashboardController::class, 'exportPdsExcel'])
        ->name('dashboard.employee.pds.export');

    // Employee Self-Service: Payslips & Attendance (read-only, scoped to logged-in user)
    Route::get('/dashboard/employee/payslips', [\App\Http\Controllers\Employee\EmployeePayslipController::class, 'index'])
        ->name('dashboard.employee.payslips');
    Route::get('/dashboard/employee/attendance', [\App\Http\Controllers\Employee\EmployeeAttendanceController::class, 'index'])
        ->name('dashboard.employee.attendance');

    Route::get('/dashboard/records-manager', [DashboardController::class, 'recordsManager'])
        ->name('dashboard.records-manager');
    Route::get('/dashboard/records-manager/employees', [\App\Http\Controllers\RecordsManagerController::class, 'index'])
        ->name('dashboard.records-manager.employees');
    Route::get('/dashboard/records-manager/departments', [DashboardController::class, 'recordsManagerDepartments'])
        ->name('dashboard.records-manager.departments');
    Route::get('/dashboard/records-manager/access', [DashboardController::class, 'recordsManagerAccess'])
        ->name('dashboard.records-manager.access');
    Route::post('/dashboard/records-manager/users', [\App\Http\Controllers\RecordsManagerController::class, 'store'])
        ->name('dashboard.records-manager.users.store');
    Route::post('/dashboard/records-manager/departments', [DashboardController::class, 'storeDepartmentRecord'])
        ->name('dashboard.records-manager.departments.store');
    Route::put('/dashboard/records-manager/departments/{department}', [DashboardController::class, 'updateDepartmentRecord'])
        ->name('dashboard.records-manager.departments.update');
    Route::put('/dashboard/records-manager/users/{user}', [\App\Http\Controllers\RecordsManagerController::class, 'update'])
        ->name('dashboard.records-manager.users.update');
    Route::delete('/dashboard/records-manager/users/{user}', [\App\Http\Controllers\RecordsManagerController::class, 'destroy'])
        ->name('dashboard.records-manager.users.destroy');
    Route::post('/records-manager/employees/{id}/reset-password', [\App\Http\Controllers\RecordsManagerController::class, 'resetPassword'])
        ->name('records-manager.employees.reset-password');

    // Self-Service: Change Password (all authenticated users)
    Route::get('/user/change-password', [\App\Http\Controllers\UserController::class, 'showChangePassword'])
        ->name('user.change-password.form');
    Route::post('/user/change-password', [\App\Http\Controllers\UserController::class, 'changePassword'])
        ->name('user.change-password');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

// Department Head routes (simple placeholders)
Route::middleware(['auth', 'role:department-head'])->group(function () {
    Route::prefix('department-head')->name('department-head.')->group(function () {
        Route::get('/', [DepartmentHeadController::class, 'index'])->name('index');
        Route::get('/pending-requests', [DepartmentHeadController::class, 'pendingRequests'])->name('pending-requests');
        Route::get('/approved-requests', [DepartmentHeadController::class, 'approvedRequests'])->name('approved-requests');
        Route::get('/statistics', [DepartmentHeadController::class, 'statistics'])->name('statistics');
        Route::get('/statistics/data', [DepartmentHeadController::class, 'statisticsData'])->name('statistics.data');
        Route::get('/statistics/details', [DepartmentHeadController::class, 'statisticsDetails'])->name('statistics.details');
        Route::get('/travel-orders', [DepartmentHeadController::class, 'travelOrders'])->name('travel-orders');
        Route::get('/travel-orders/{id}', [DepartmentHeadController::class, 'showTravelOrder'])->name('department-head.travel-orders.show');
        Route::get('/office-orders', [DepartmentHeadController::class, 'officeOrders'])->name('office-orders');
        Route::get('/filed-travel-orders', [DepartmentHeadController::class, 'filedTravelOrders'])->name('filed-travel-orders');
        Route::get('/filed-office-orders', [DepartmentHeadController::class, 'filedOfficeOrders'])->name('filed-office-orders');
    });
});

// Administrative Officer routes (mirrors Department Head, excludes Self-Service)
Route::middleware(['auth', 'role:administrative-officer'])->group(function () {
    Route::prefix('admin-officer')->name('admin-officer.')->group(function () {
        Route::get('/', [\App\Http\Controllers\AdministrativeOfficerController::class, 'index'])->name('index');
        Route::get('/pending-requests', [\App\Http\Controllers\AdministrativeOfficerController::class, 'pendingRequests'])->name('pending-requests');
        Route::get('/approved-requests', [\App\Http\Controllers\AdministrativeOfficerController::class, 'approvedRequests'])->name('approved-requests');
        Route::get('/statistics', [\App\Http\Controllers\AdministrativeOfficerController::class, 'statistics'])->name('statistics');
        Route::get('/statistics/data', [\App\Http\Controllers\AdministrativeOfficerController::class, 'statisticsData'])->name('statistics.data');
        Route::get('/statistics/details', [\App\Http\Controllers\AdministrativeOfficerController::class, 'statisticsDetails'])->name('statistics.details');
        Route::get('/travel-orders', [\App\Http\Controllers\AdministrativeOfficerController::class, 'travelOrders'])->name('travel-orders');
        Route::get('/travel-orders/{id}', [\App\Http\Controllers\AdministrativeOfficerController::class, 'showTravelOrder'])->name('travel-orders.show');
        Route::get('/office-orders', [\App\Http\Controllers\AdministrativeOfficerController::class, 'officeOrders'])->name('office-orders');
        Route::get('/filed-travel-orders', [\App\Http\Controllers\AdministrativeOfficerController::class, 'filedTravelOrders'])->name('filed-travel-orders');
        Route::get('/filed-office-orders', [\App\Http\Controllers\AdministrativeOfficerController::class, 'filedOfficeOrders'])->name('filed-office-orders');
    });

    // Administrative Officer approval actions
    Route::post('/admin-officer/leave/{id}/approve', [\App\Http\Controllers\AdministrativeOfficerController::class, 'approve'])->name('admin-officer.leave.approve');
    Route::post('/admin-officer/leave/{id}/reject', [\App\Http\Controllers\AdministrativeOfficerController::class, 'reject'])->name('admin-officer.leave.reject');
    Route::post('/admin-officer/eta/{id}/approve', [\App\Http\Controllers\AdministrativeOfficerController::class, 'approveEta'])->name('admin-officer.eta.approve');
    Route::post('/admin-officer/eta/{id}/reject', [\App\Http\Controllers\AdministrativeOfficerController::class, 'rejectEta'])->name('admin-officer.eta.reject');
    Route::post('/admin-officer/locator/{id}/approve', [\App\Http\Controllers\AdministrativeOfficerController::class, 'approveLocator'])->name('admin-officer.locator.approve');
    Route::post('/admin-officer/locator/{id}/reject', [\App\Http\Controllers\AdministrativeOfficerController::class, 'rejectLocator'])->name('admin-officer.locator.reject');
});

// Shared dashboard API endpoints (accessible by both department-head and administrative-officer)
Route::middleware(['auth', 'throttle:api', 'role:department-head,administrative-officer'])->group(function () {
    Route::get('/api/department/dashboard-metrics', [\App\Http\Controllers\DepartmentHeadController::class, 'dashboardMetrics'])->name('api.department.dashboard-metrics');
    Route::get('/api/department/kpis', [\App\Http\Controllers\DepartmentHeadController::class, 'dashboardMetrics'])->name('api.department.kpis');
    Route::get('/api/department/employees-on-duty', [\App\Http\Controllers\DepartmentHeadController::class, 'employeesOnDuty'])->name('api.department.employees-on-duty');
    Route::get('/api/department/leave-requests', [\App\Http\Controllers\DepartmentHeadController::class, 'leaveRequestsList'])->name('api.department.leave-requests');
    Route::get('/api/department/locator-requests', [\App\Http\Controllers\DepartmentHeadController::class, 'locatorRequestsList'])->name('api.department.locator-requests');
    Route::get('/api/department/eta-requests', [\App\Http\Controllers\DepartmentHeadController::class, 'etaRequestsList'])->name('api.department.eta-requests');
    // Travel & Office Order API endpoints (shared)
    Route::get('/api/department-employees', [\App\Http\Controllers\TravelOrderController::class, 'getDepartmentEmployees'])->name('api.department-employees');
    Route::post('/api/travel-orders', [\App\Http\Controllers\TravelOrderController::class, 'store'])->name('api.travel-orders');
    Route::get('/api/department/travel-orders', [\App\Http\Controllers\TravelOrderController::class, 'index'])->name('api.department.travel-orders');
    Route::get('/api/travel-orders/{id}', [\App\Http\Controllers\TravelOrderController::class, 'show'])->name('api.travel-orders.show');
    Route::post('/api/office-orders', [\App\Http\Controllers\OfficeOrderController::class, 'store'])->name('api.office-orders');
    Route::get('/api/department/office-orders', [\App\Http\Controllers\OfficeOrderController::class, 'index'])->name('api.department.office-orders');
    Route::get('/api/office-orders/{id}', [\App\Http\Controllers\OfficeOrderController::class, 'show'])->name('api.office-orders.show');
});

// Department Head-only actions
Route::middleware(['auth', 'role:department-head'])->group(function () {
    Route::post('/department-head/leave/{id}/approve', [\App\Http\Controllers\DepartmentHeadController::class, 'approve'])->name('department-head.leave.approve');
    Route::post('/department-head/leave/{id}/reject', [\App\Http\Controllers\DepartmentHeadController::class, 'reject'])->name('department-head.leave.reject');
    // ETA and Locator actions
    Route::post('/department-head/eta/{id}/approve', [\App\Http\Controllers\DepartmentHeadController::class, 'approveEta'])->name('department-head.eta.approve');
    Route::post('/department-head/eta/{id}/reject', [\App\Http\Controllers\DepartmentHeadController::class, 'rejectEta'])->name('department-head.eta.reject');

    Route::post('/department-head/locator/{id}/approve', [\App\Http\Controllers\DepartmentHeadController::class, 'approveLocator'])->name('department-head.locator.approve');
    Route::post('/department-head/locator/{id}/reject', [\App\Http\Controllers\DepartmentHeadController::class, 'rejectLocator'])->name('department-head.locator.reject');
});

// Leave Manager pages
Route::middleware(['auth', 'role:leave-manager'])->group(function () {
    Route::get('/leave-manager/manage-balance', [LeaveManagerController::class, 'manageBalance'])
        ->name('leave-manager.manage-balance');

    // Update a leave balance (inline edit)
    Route::patch('/leave-manager/manage-balance/{balance}', [LeaveManagerController::class, 'updateBalance'])
        ->name('leave-manager.update-balance');

    Route::get('/leave-manager/manage-credits', [LeaveManagerController::class, 'manageCredits'])
        ->name('leave-manager.manage-credits');

    // Apply credits / deductions (single or batch)
    Route::post('/leave-manager/apply-credits', [LeaveManagerController::class, 'applyCredits'])
        ->name('leave-manager.apply-credits');

    Route::get('/leave-manager/approved-leaves', function () {
        return redirect()->route('dashboard');
    })->name('leave-manager.approved-leaves');

    Route::get('/leave-manager/cancel-leaves', [LeaveManagerController::class, 'cancelLeaves'])
        ->name('leave-manager.cancel-leaves');
    // API endpoints used by cancel-leaves UI
    Route::post('/api/leave/cancel-date', [LeaveManagerController::class, 'apiCancelDate'])
        ->name('api.leave.cancel-date');
    Route::get('/api/employee-search', [LeaveManagerController::class, 'employeeSearch'])
        ->name('api.employee.search');

    // Bulk cancel leaves on a declared holiday
    Route::post('/api/leave/bulk-cancel-holiday', [LeaveManagerController::class, 'apiBulkCancelByHoliday'])
        ->name('api.leave.bulk-cancel-holiday');

    // Holiday management
    Route::post('/api/holidays', [LeaveManagerController::class, 'storeHoliday'])
        ->name('api.holidays.store');
    Route::get('/api/holidays', [LeaveManagerController::class, 'listHolidays'])
        ->name('api.holidays.list');
});

Route::middleware(['auth', 'role:hr-manager'])->group(function () {
    Route::get('/dashboard/hr-manager', [HRManagerController::class, 'index'])
        ->name('hr-manager.dashboard');
    Route::get('/dashboard/hr-manager/chart-data', [HRManagerController::class, 'getChartData'])
        ->name('hr-manager.chart-data');

    Route::get('/dashboard/hr-manager/records', [HRManagerController::class, 'records'])
        ->name('hr-manager.records');
    Route::get('/dashboard/hr-manager/leave', [HRManagerController::class, 'leave'])
        ->name('hr-manager.leave');
    Route::get('/dashboard/hr-manager/frontdesk', [HRManagerController::class, 'frontdesk'])
        ->name('hr-manager.frontdesk');
    Route::get('/dashboard/hr-manager/reports', [HRManagerController::class, 'reports'])
        ->name('hr-manager.reports');
    Route::get('/dashboard/hr-manager/audit', [HRManagerController::class, 'audit'])
        ->name('hr-manager.audit');
    Route::get('/dashboard/hr-manager/roles', [HRManagerController::class, 'roles'])
        ->name('hr-manager.roles');
    Route::get('/dashboard/hr-manager/settings', [HRManagerController::class, 'settings'])
        ->name('hr-manager.settings');
    Route::post('/dashboard/hr-manager/settings', [HRManagerController::class, 'updateSettings'])
        ->name('hr-manager.settings.update');

    Route::get('/dashboard/hr-manager/records/data', [HRManagerController::class, 'recordsData'])
        ->name('hr-manager.records.data');
    Route::post('/dashboard/hr-manager/records/{user}/action', [HRManagerController::class, 'recordsAction'])
        ->name('hr-manager.records.action');
    Route::get('/dashboard/hr-manager/leave/data', [HRManagerController::class, 'leaveData'])
        ->name('hr-manager.leave.data');
    Route::post('/dashboard/hr-manager/leave/{leaveRequest}/action', [HRManagerController::class, 'leaveAction'])
        ->name('hr-manager.leave.action');
    Route::get('/dashboard/hr-manager/frontdesk/data', [HRManagerController::class, 'frontdeskData'])
        ->name('hr-manager.frontdesk.data');
    Route::post('/dashboard/hr-manager/frontdesk/{documentRequest}/action', [HRManagerController::class, 'frontdeskAction'])
        ->name('hr-manager.frontdesk.action');
    Route::post('/dashboard/hr-manager/frontdesk/{documentRequest}/complete', [HRManagerController::class, 'frontdeskComplete'])
        ->name('hr-manager.frontdesk.complete');
    Route::get('/dashboard/hr-manager/reports/export/{format}', [HRManagerController::class, 'exportReport'])
        ->name('hr-manager.reports.export');
    Route::get('/dashboard/hr-manager/audit/data', [HRManagerController::class, 'auditData'])
        ->name('hr-manager.audit.data');
    Route::get('/dashboard/hr-manager/employees/filter', [HRManagerController::class, 'getEmployeesByFilter'])
        ->name('hr-manager.employees.filter');
});

// Mayor's Office routes
Route::middleware(['auth', 'role:mayor'])->group(function () {
    Route::prefix('mayor')->name('mayor.')->group(function () {
        Route::get('/dashboard', [MayorController::class, 'dashboard'])->name('dashboard');
        Route::get('/chart-data', [MayorController::class, 'getChartData'])->name('chart-data');
        Route::get('/employees/filter', [MayorController::class, 'getEmployeesByFilter'])->name('employees.filter');
        Route::get('/reports', [MayorController::class, 'reports'])->name('reports');
        Route::get('/approvals', [MayorController::class, 'approvals'])->name('approvals');
        Route::get('/policies', [MayorController::class, 'policies'])->name('policies');
        Route::get('/employees', [MayorController::class, 'employees'])->name('employees');
        Route::get('/events', [MayorController::class, 'events'])->name('events');
        Route::get('/settings', [MayorController::class, 'settings'])->name('settings');

        // Mayor leave approval/rejection for Department Head and HR Manager requests
        Route::post('/leave/{id}/approve', [MayorController::class, 'approveLeave'])->name('leave.approve');
        Route::post('/leave/{id}/reject', [MayorController::class, 'rejectLeave'])->name('leave.reject');
        Route::get('/leave-requests/data', [MayorController::class, 'leaveRequestsData'])->name('leave-requests.data');

        // Mayor travel order approval
        Route::get('/travel-order-approvals', [MayorController::class, 'travelOrderApprovals'])->name('travel-order-approvals');
        Route::get('/travel-orders/{id}', [MayorController::class, 'viewTravelOrder'])->name('travel-orders.view');
        Route::post('/travel-orders/{id}/approve', [MayorController::class, 'approveTravelOrder'])->name('travel-orders.approve');
        Route::post('/travel-orders/{id}/reject', [MayorController::class, 'rejectTravelOrder'])->name('travel-orders.reject');
    });
});

// ── Payroll Manager Module ──────────────────────────────────────────────
Route::middleware(['auth', 'role:payroll-manager'])->prefix('payroll-manager')->name('payroll.')->group(function () {
    Route::get('/dashboard', [PayrollDashboardController::class, 'index'])->name('dashboard');

    // Payroll Runs
    Route::get('/runs', [PayrollRunController::class, 'index'])->name('runs.index');
    Route::get('/runs/create', [PayrollRunController::class, 'create'])->name('runs.create');
    Route::post('/runs/store', [PayrollRunController::class, 'store'])->name('runs.store');
    Route::get('/runs/{id}', [PayrollRunController::class, 'show'])->name('runs.show');
    Route::post('/runs/{id}/compute', [PayrollRunController::class, 'compute'])->name('runs.compute');
    Route::post('/runs/{id}/lock', [PayrollRunController::class, 'lock'])->name('runs.lock');
    Route::get('/runs/{id}/export', [PayrollRunController::class, 'export'])->name('runs.export');

    // Resource routes
    Route::resource('attendance', PayrollAttendanceController::class)->names([
        'index' => 'attendance.index',
        'create' => 'attendance.create',
        'store' => 'attendance.store',
        'show' => 'attendance.show',
        'edit' => 'attendance.edit',
        'update' => 'attendance.update',
        'destroy' => 'attendance.destroy',
    ]);
    Route::resource('plantilla', PlantillaController::class)->names([
        'index' => 'plantilla.index',
        'create' => 'plantilla.create',
        'store' => 'plantilla.store',
        'show' => 'plantilla.show',
        'edit' => 'plantilla.edit',
        'update' => 'plantilla.update',
        'destroy' => 'plantilla.destroy',
    ]);

    // Plantilla Employee Assignments
    Route::post('plantilla/{plantilla}/assignments', [EmployeeAssignmentController::class, 'store'])->name('plantilla.assignments.store');
    Route::put('plantilla/{plantilla}/assignments/{assignment}', [EmployeeAssignmentController::class, 'update'])->name('plantilla.assignments.update');
    Route::delete('plantilla/{plantilla}/assignments/{assignment}', [EmployeeAssignmentController::class, 'destroy'])->name('plantilla.assignments.destroy');

    Route::resource('salary-matrix', SalaryMatrixController::class)->names([
        'index' => 'salary-matrix.index',
        'create' => 'salary-matrix.create',
        'store' => 'salary-matrix.store',
        'show' => 'salary-matrix.show',
        'edit' => 'salary-matrix.edit',
        'update' => 'salary-matrix.update',
        'destroy' => 'salary-matrix.destroy',
    ]);
    Route::resource('earnings', EarningsController::class)->names([
        'index' => 'earnings.index',
        'create' => 'earnings.create',
        'store' => 'earnings.store',
        'show' => 'earnings.show',
        'edit' => 'earnings.edit',
        'update' => 'earnings.update',
        'destroy' => 'earnings.destroy',
    ]);
    Route::resource('deductions', DeductionsController::class)->names([
        'index' => 'deductions.index',
        'create' => 'deductions.create',
        'store' => 'deductions.store',
        'show' => 'deductions.show',
        'edit' => 'deductions.edit',
        'update' => 'deductions.update',
        'destroy' => 'deductions.destroy',
    ]);
    Route::resource('leave-integration', LeaveIntegrationController::class)->names([
        'index' => 'leave-integration.index',
        'create' => 'leave-integration.create',
        'store' => 'leave-integration.store',
        'show' => 'leave-integration.show',
        'edit' => 'leave-integration.edit',
        'update' => 'leave-integration.update',
        'destroy' => 'leave-integration.destroy',
    ]);
    Route::resource('exceptions', ExceptionsController::class)->names([
        'index' => 'exceptions.index',
        'create' => 'exceptions.create',
        'store' => 'exceptions.store',
        'show' => 'exceptions.show',
        'edit' => 'exceptions.edit',
        'update' => 'exceptions.update',
        'destroy' => 'exceptions.destroy',
    ]);
    Route::resource('approvals', ApprovalsController::class)->names([
        'index' => 'approvals.index',
        'create' => 'approvals.create',
        'store' => 'approvals.store',
        'show' => 'approvals.show',
        'edit' => 'approvals.edit',
        'update' => 'approvals.update',
        'destroy' => 'approvals.destroy',
    ]);
    Route::resource('payslips', PayslipController::class)->names([
        'index' => 'payslips.index',
        'create' => 'payslips.create',
        'store' => 'payslips.store',
        'show' => 'payslips.show',
        'edit' => 'payslips.edit',
        'update' => 'payslips.update',
        'destroy' => 'payslips.destroy',
    ]);
    Route::resource('reports', PayrollReportsController::class)->names([
        'index' => 'reports.index',
        'create' => 'reports.create',
        'store' => 'reports.store',
        'show' => 'reports.show',
        'edit' => 'reports.edit',
        'update' => 'reports.update',
        'destroy' => 'reports.destroy',
    ]);
    Route::resource('audit-logs', PayrollAuditLogController::class)->names([
        'index' => 'audit-logs.index',
        'create' => 'audit-logs.create',
        'store' => 'audit-logs.store',
        'show' => 'audit-logs.show',
        'edit' => 'audit-logs.edit',
        'update' => 'audit-logs.update',
        'destroy' => 'audit-logs.destroy',
    ]);
    Route::resource('settings', PayrollSettingsController::class)->names([
        'index' => 'settings.index',
        'create' => 'settings.create',
        'store' => 'settings.store',
        'show' => 'settings.show',
        'edit' => 'settings.edit',
        'update' => 'settings.update',
        'destroy' => 'settings.destroy',
    ]);
});

Route::fallback(function () {
    abort(404);
});

// Development-only: preview leave status email in browser
Route::get('/dev/preview-leave-email', function () {
    if (!app()->environment('local')) {
        abort(404);
    }

    $employee = \App\Models\User::first();
    $leave = \App\Models\LeaveRequest::latest()->first();
    if (! $employee || ! $leave) {
        return 'No sample employee or leave request found in the database.';
    }

    if (! empty($employee->Dept_id)) {
        $dept = \App\Models\Department::find($employee->Dept_id);
        $employee->department_name = $dept->Dept_name ?? null;
    }

    $formatted = [
        'filed' => \Carbon\Carbon::parse($leave->created_at)->format('l, F j, Y'),
        'start' => \Carbon\Carbon::parse($leave->start_date)->format('l, F j, Y'),
        'end' => \Carbon\Carbon::parse($leave->end_date)->format('l, F j, Y'),
    ];

    $mailable = new \App\Mail\LeaveRequestStatusNotification($employee, $leave, $formatted, 'approved');
    return $mailable->render();
})->name('dev.preview.leave.email');