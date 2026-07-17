<?php

use App\Http\Controllers\AdministrativeOfficerCancellationController;
use App\Http\Controllers\AdministrativeOfficerController;
use App\Http\Controllers\Attendance\AttendanceAdjustmentReviewController;
use App\Http\Controllers\Attendance\AttendanceAdjustmentSummaryController;
use App\Http\Controllers\Attendance\AttendanceImportController;
use App\Http\Controllers\Attendance\DtrController;
use App\Http\Controllers\Attendance\DtrExcuseController;
use App\Http\Controllers\Attendance\EmployeeScheduleController;
use App\Http\Controllers\Attendance\ShiftController;
use App\Http\Controllers\Attendance\ShiftLogController;
use App\Http\Controllers\Attendance\ShiftManagementAccessController;
use App\Http\Controllers\Attendance\ShiftScheduleController;
use App\Http\Controllers\Attendance\TimeLogsMonitoringController;
use App\Http\Controllers\Attendance\WorkforceCalendarController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentHeadCancellationController;
use App\Http\Controllers\DepartmentHeadController;
use App\Http\Controllers\DevController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\DocumentSettingsController;
use App\Http\Controllers\Employee\EmployeePayslipController;
use App\Http\Controllers\Employee\EtaController;
use App\Http\Controllers\Employee\LocatorController;
use App\Http\Controllers\ExportJobController;
use App\Http\Controllers\FrontDeskController;
use App\Http\Controllers\HRManagerController;
use App\Http\Controllers\LeaveManagerController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\MayorController;
use App\Http\Controllers\OfficeOrderController;
use App\Http\Controllers\OicAssignmentController;
use App\Http\Controllers\Payroll\ApprovalsController;
use App\Http\Controllers\Payroll\AttendanceController as PayrollAttendanceController;
use App\Http\Controllers\Payroll\AuditLogController as PayrollAuditLogController;
use App\Http\Controllers\Payroll\DeductionsController;
use App\Http\Controllers\Payroll\EarningsController;
use App\Http\Controllers\Payroll\EmployeeAssignmentController;
use App\Http\Controllers\Payroll\EmployeeEarningController;
use App\Http\Controllers\Payroll\ExceptionsController;
use App\Http\Controllers\Payroll\LeaveIntegrationController;
use App\Http\Controllers\Payroll\PayrollDashboardController;
use App\Http\Controllers\Payroll\PayrollRunController;
use App\Http\Controllers\Payroll\PayrollSettingsController;
use App\Http\Controllers\Payroll\PayslipController;
use App\Http\Controllers\Payroll\PlantillaController;
use App\Http\Controllers\Payroll\ReportsController as PayrollReportsController;
use App\Http\Controllers\Payroll\SalaryMatrixController;
use App\Http\Controllers\RecordsManagerController;
use App\Http\Controllers\TravelOrderController;
use App\Http\Controllers\UniformInspectionController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\LimitPayloadSize;
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

// Background export jobs
Route::middleware('auth')->group(function () {
    Route::post('/export-jobs', [ExportJobController::class, 'create'])->name('export-jobs.create');
    Route::get('/export-jobs/{id}/status', [ExportJobController::class, 'status'])->name('export-jobs.status');
    Route::get('/export-jobs/{id}/download', [ExportJobController::class, 'download'])->name('export-jobs.download');
});

// Employee Leave Management
Route::middleware(['auth', 'deny.job.order'])->group(function () {
    Route::get('/employee/leave-management', [LeaveRequestController::class, 'index'])->name('employee.leave.management');
    Route::post('/employee/leave-management/apply', [LeaveRequestController::class, 'store'])->name('employee.leave.apply');
    Route::post('/employee/leave-management/{id}/approve', [LeaveRequestController::class, 'approve'])->name('employee.leave.approve');
    Route::get('/employee/leave-management/{id}', [LeaveRequestController::class, 'show'])->name('employee.leave.show');
    Route::get('/employee/leave-management/{id}/edit', [LeaveRequestController::class, 'edit'])->name('employee.leave.edit');
    Route::patch('/employee/leave-management/{id}/cancel', [LeaveRequestController::class, 'cancel'])->name('employee.leave.cancel');
    Route::post('/employee/leave-management/{id}/request-cancellation', [LeaveRequestController::class, 'requestCancellation'])->name('employee.leave.request-cancellation');
    Route::post('/employee/leave-management/{id}/reschedule', [LeaveRequestController::class, 'requestReschedule'])->name('employee.leave.reschedule');

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
    Route::get('/dashboard/employee/eta-locator', [EtaController::class, 'index'])
        ->name('dashboard.employee.eta');
    Route::post('/dashboard/employee/eta-locator', [EtaController::class, 'store'])
        ->name('employee.eta.store');
    Route::get('/dashboard/employee/eta-locator/data', [EtaController::class, 'data'])
        ->name('employee.eta.data');
    Route::get('/dashboard/employee/eta-locator/{eta}/print', [EtaController::class, 'printSingle'])
        ->name('employee.eta.print.single');
    Route::post('/dashboard/employee/eta-locator/{eta}/cancel', [EtaController::class, 'cancel'])
        ->name('employee.eta.cancel');
    Route::get('/dashboard/employee/leave/{leave}/print', [LeaveRequestController::class, 'printSingle'])
        ->name('employee.leave.print.single');
    // API: check leave status (printing_allowed, status)
    Route::get('/api/leave/{leave}/status', [LeaveRequestController::class, 'apiStatus'])->name('api.leave.status');
    Route::get('/dashboard/employee/eta-locator/print', [EtaController::class, 'print'])
        ->name('employee.eta.print');
    Route::get('/dashboard/employee/locator', [LocatorController::class, 'index'])
        ->name('dashboard.employee.locator');
    Route::post('/dashboard/employee/locator', [LocatorController::class, 'store'])
        ->name('employee.locator.store');
    Route::post('/dashboard/employee/locator/{locator}/cancel', [LocatorController::class, 'cancel'])
        ->name('employee.locator.cancel');
    Route::get('/dashboard/employee/locator/{locator}/edit', [LocatorController::class, 'edit'])
        ->name('employee.locator.edit');
    Route::put('/dashboard/employee/locator/{locator}', [LocatorController::class, 'update'])
        ->name('employee.locator.update');
    Route::get('/dashboard/employee/locator/data', [LocatorController::class, 'data'])
        ->name('employee.locator.data');
    Route::get('/dashboard/employee/locator/{locator}/print', [LocatorController::class, 'printSingle'])
        ->name('employee.locator.print.single');
    Route::get('/dashboard/employee/request-documents', [DocumentRequestController::class, 'index'])
        ->name('dashboard.employee.request-documents');
    Route::post('/document-requests', [DocumentRequestController::class, 'store'])
        ->name('document-requests.store');
    Route::get('/document-requests/{documentRequest}/preview', [DocumentRequestController::class, 'preview'])
        ->name('document-requests.preview');
    Route::get('/document-requests/{documentRequest}/print', [DocumentRequestController::class, 'print'])
        ->name('document-requests.print');
    Route::get('/dashboard/employee/front-desk', [FrontDeskController::class, 'index'])
        ->name('front-desk.index');
    Route::get('/dashboard/employee/pending-requests', [FrontDeskController::class, 'pendingRequests'])
        ->name('employee.pending-requests');
    Route::get('/dashboard/employee/approved-requests', [FrontDeskController::class, 'approvedRequests'])
        ->name('employee.approved-requests');
    Route::get('/dashboard/employee/document-settings', [DocumentSettingsController::class, 'index'])
        ->name('employee.document-settings');
    Route::get('/dashboard/employee/document-settings/create', [DocumentSettingsController::class, 'create'])
        ->name('employee.document-settings.create');
    Route::post('/dashboard/employee/document-settings', [DocumentSettingsController::class, 'store'])
        ->name('employee.document-settings.store');
    Route::get('/dashboard/employee/document-settings/{documentType}/edit', [DocumentSettingsController::class, 'edit'])
        ->name('employee.document-settings.edit');
    Route::put('/dashboard/employee/document-settings/{documentType}', [DocumentSettingsController::class, 'update'])
        ->name('employee.document-settings.update');
    Route::delete('/dashboard/employee/document-settings/{documentType}', [DocumentSettingsController::class, 'destroy'])
        ->name('employee.document-settings.destroy');
    Route::get('/dashboard/employee/front-desk/requests', [FrontDeskController::class, 'fetchRequests'])
        ->name('front-desk.requests');
    Route::post('/dashboard/employee/front-desk/accept', [FrontDeskController::class, 'acceptRequest'])
        ->name('front-desk.accept');
    Route::post('/dashboard/employee/front-desk/reject', [FrontDeskController::class, 'rejectRequest'])
        ->name('front-desk.reject');
    Route::post('/dashboard/employee/front-desk/complete', [FrontDeskController::class, 'completeRequest'])
        ->name('front-desk.complete');
    Route::post('/requests/{id}/complete', [FrontDeskController::class, 'complete'])
        ->name('requests.complete');
    Route::get('/dashboard/employee/front-desk/print/{id}', [FrontDeskController::class, 'printRequest'])
        ->name('front-desk.print-request');
    Route::get('/dashboard/employee/front-desk/word/{id}', [FrontDeskController::class, 'downloadWord'])
        ->name('front-desk.download-word');
    Route::post('/dashboard/employee/front-desk/update-status', [FrontDeskController::class, 'updateStatus'])
        ->name('front-desk.update-status');
    Route::post('/dashboard/employee/front-desk/print-report', [FrontDeskController::class, 'printReport'])
        ->name('front-desk.print-report');
    Route::post('/dashboard/employee/pds/save-draft', [DashboardController::class, 'savePdsDraft'])
        ->name('dashboard.employee.pds.save-draft');
    Route::get('/dashboard/employee/pds/export', [DashboardController::class, 'exportPdsExcel'])
        ->name('dashboard.employee.pds.export');

    // Employee Self-Service: Payslips (read-only, scoped to logged-in user)
    Route::get('/dashboard/employee/payslips', [EmployeePayslipController::class, 'index'])
        ->name('dashboard.employee.payslips');

    // Attendance DTR - list view and Form 48 download (role-branching handled in controller)
    Route::get('/attendance/dtr', [DtrController::class, 'index'])
        ->name('attendance.dtr');
    Route::get('/attendance/dtr/data', [DtrController::class, 'data'])
        ->name('attendance.dtr.data');
    Route::get('/attendance/dtr/download', [DtrController::class, 'downloadForm48'])
        ->name('attendance.dtr.download');
    Route::get('/attendance/dtr/download-dept-zip', [DtrController::class, 'downloadDepartmentZip'])
        ->name('attendance.dtr.download-dept-zip');
    Route::get('/attendance/dtr/download-dept', [DtrController::class, 'downloadDepartmentForm48'])
        ->name('attendance.dtr.download-dept');

    Route::get('/dashboard/records-manager', [DashboardController::class, 'recordsManager'])
        ->name('dashboard.records-manager');
    Route::get('/dashboard/records-manager/employees', [RecordsManagerController::class, 'index'])
        ->name('dashboard.records-manager.employees');
    Route::get('/dashboard/records-manager/departments', [DashboardController::class, 'recordsManagerDepartments'])
        ->name('dashboard.records-manager.departments');
    Route::get('/dashboard/records-manager/access', [DashboardController::class, 'recordsManagerAccess'])
        ->name('dashboard.records-manager.access');
    Route::post('/dashboard/records-manager/users', [RecordsManagerController::class, 'store'])
        ->name('dashboard.records-manager.users.store');
    Route::post('/dashboard/records-manager/departments', [DashboardController::class, 'storeDepartmentRecord'])
        ->name('dashboard.records-manager.departments.store');
    Route::put('/dashboard/records-manager/departments/{department}', [DashboardController::class, 'updateDepartmentRecord'])
        ->name('dashboard.records-manager.departments.update');
    Route::put('/dashboard/records-manager/users/{user}', [RecordsManagerController::class, 'update'])
        ->name('dashboard.records-manager.users.update');
    Route::delete('/dashboard/records-manager/users/{user}', [RecordsManagerController::class, 'destroy'])
        ->name('dashboard.records-manager.users.destroy');
    Route::post('/records-manager/employees/{id}/reset-password', [RecordsManagerController::class, 'resetPassword'])
        ->name('records-manager.employees.reset-password');
    Route::get('/dashboard/records-manager/employees/import-template', [RecordsManagerController::class, 'downloadImportTemplate'])
        ->name('dashboard.records-manager.employees.import-template');
    Route::post('/dashboard/records-manager/employees/import', [RecordsManagerController::class, 'import'])
        ->name('dashboard.records-manager.employees.import');

    // Self-Service: Change Password (all authenticated users)
    Route::get('/user/change-password', [UserController::class, 'showChangePassword'])
        ->name('user.change-password.form');
    Route::post('/user/change-password', [UserController::class, 'changePassword'])
        ->name('user.change-password');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

// Department Head routes (accessible by both Department Head and Administrative Officer)
Route::middleware(['auth', 'role:department-head,administrative-officer'])->group(function () {
    Route::prefix('department-head')->name('department-head.')->group(function () {
        Route::get('/', [DepartmentHeadController::class, 'index'])->name('index');
        Route::get('/pending-requests', [DepartmentHeadController::class, 'pendingRequests'])->name('pending-requests');
        Route::get('/pending-requests/leave/data', [DepartmentHeadController::class, 'pendingRequestsLeaveData'])->name('pending-requests.leave-data');
        Route::get('/pending-requests/eta/data', [DepartmentHeadController::class, 'pendingRequestsEtaData'])->name('pending-requests.eta-data');
        Route::get('/pending-requests/locator/data', [DepartmentHeadController::class, 'pendingRequestsLocatorData'])->name('pending-requests.locator-data');
        Route::get('/approved-requests', [DepartmentHeadController::class, 'approvedRequests'])->name('approved-requests');
        Route::get('/approved-requests/leave/data', [DepartmentHeadController::class, 'approvedRequestsLeaveData'])->name('approved-requests.leave-data');
        Route::get('/approved-requests/eta/data', [DepartmentHeadController::class, 'approvedRequestsEtaData'])->name('approved-requests.eta-data');
        Route::get('/approved-requests/locator/data', [DepartmentHeadController::class, 'approvedRequestsLocatorData'])->name('approved-requests.locator-data');
        Route::get('/statistics', [DepartmentHeadController::class, 'statistics'])->name('statistics');
        Route::get('/statistics/data', [DepartmentHeadController::class, 'statisticsData'])->name('statistics.data');
        Route::get('/statistics/details', [DepartmentHeadController::class, 'statisticsDetails'])->name('statistics.details');
        Route::get('/travel-orders', [DepartmentHeadController::class, 'travelOrders'])->name('travel-orders');
        Route::get('/travel-orders/{id}', [DepartmentHeadController::class, 'showTravelOrder'])->name('department-head.travel-orders.show');
        Route::get('/office-orders', [DepartmentHeadController::class, 'officeOrders'])->name('office-orders');
        Route::get('/filed-travel-orders', [DepartmentHeadController::class, 'filedTravelOrders'])->name('filed-travel-orders');
        Route::get('/filed-office-orders', [DepartmentHeadController::class, 'filedOfficeOrders'])->name('filed-office-orders');

        // OIC assignment management
        Route::get('/oic-assignments', [OicAssignmentController::class, 'index'])->name('oic-assignments.index');
        Route::post('/oic-assignments', [OicAssignmentController::class, 'store'])->name('oic-assignments.store');
        Route::delete('/oic-assignments/{id}', [OicAssignmentController::class, 'destroy'])->name('oic-assignments.destroy');
    });
});

// Administrative Officer routes (mirrors Department Head, excludes Self-Service)
Route::middleware(['auth', 'role:administrative-officer'])->group(function () {
    Route::prefix('admin-officer')->name('admin-officer.')->group(function () {
        Route::get('/', [AdministrativeOfficerController::class, 'index'])->name('index');
        Route::get('/pending-requests', [AdministrativeOfficerController::class, 'pendingRequests'])->name('pending-requests');
        Route::get('/pending-requests/leave/data', [AdministrativeOfficerController::class, 'pendingRequestsLeaveData'])->name('pending-requests.leave-data');
        Route::get('/pending-requests/eta/data', [AdministrativeOfficerController::class, 'pendingRequestsEtaData'])->name('pending-requests.eta-data');
        Route::get('/pending-requests/locator/data', [AdministrativeOfficerController::class, 'pendingRequestsLocatorData'])->name('pending-requests.locator-data');
        Route::get('/approved-requests', [AdministrativeOfficerController::class, 'approvedRequests'])->name('approved-requests');
        Route::get('/approved-requests/leave/data', [AdministrativeOfficerController::class, 'approvedRequestsLeaveData'])->name('approved-requests.leave-data');
        Route::get('/approved-requests/eta/data', [AdministrativeOfficerController::class, 'approvedRequestsEtaData'])->name('approved-requests.eta-data');
        Route::get('/approved-requests/locator/data', [AdministrativeOfficerController::class, 'approvedRequestsLocatorData'])->name('approved-requests.locator-data');
        Route::get('/statistics', [AdministrativeOfficerController::class, 'statistics'])->name('statistics');
        Route::get('/statistics/data', [AdministrativeOfficerController::class, 'statisticsData'])->name('statistics.data');
        Route::get('/statistics/details', [AdministrativeOfficerController::class, 'statisticsDetails'])->name('statistics.details');
        Route::get('/travel-orders', [AdministrativeOfficerController::class, 'travelOrders'])->name('travel-orders');
        Route::get('/travel-orders/{id}', [AdministrativeOfficerController::class, 'showTravelOrder'])->name('travel-orders.show');
        Route::get('/office-orders', [AdministrativeOfficerController::class, 'officeOrders'])->name('office-orders');
        Route::get('/filed-travel-orders', [AdministrativeOfficerController::class, 'filedTravelOrders'])->name('filed-travel-orders');
        Route::get('/filed-office-orders', [AdministrativeOfficerController::class, 'filedOfficeOrders'])->name('filed-office-orders');
        Route::get('/monitoring-matrix', [AdministrativeOfficerController::class, 'monitoringMatrix'])->name('monitoring-matrix');
        Route::get('/monitoring-matrix/export', [AdministrativeOfficerController::class, 'exportMonitoringMatrix'])->name('monitoring-matrix.export');
    });

    // Administrative Officer approval actions
    Route::post('/admin-officer/leave/{id}/approve', [AdministrativeOfficerController::class, 'approve'])->name('admin-officer.leave.approve');
    Route::post('/admin-officer/leave/{id}/reject', [AdministrativeOfficerController::class, 'reject'])->name('admin-officer.leave.reject');
    Route::post('/admin-officer/leave/{id}/allow-printing', [AdministrativeOfficerController::class, 'allowPrinting'])->name('admin-officer.leave.allow-printing');
    Route::post('/admin-officer/eta/{id}/approve', [AdministrativeOfficerController::class, 'approveEta'])->name('admin-officer.eta.approve');
    Route::post('/admin-officer/eta/{id}/reject', [AdministrativeOfficerController::class, 'rejectEta'])->name('admin-officer.eta.reject');
    Route::post('/admin-officer/locator/{id}/approve', [AdministrativeOfficerController::class, 'approveLocator'])->name('admin-officer.locator.approve');
    Route::post('/admin-officer/locator/{id}/reject', [AdministrativeOfficerController::class, 'rejectLocator'])->name('admin-officer.locator.reject');
    Route::post('/admin-officer/locator/{id}/record-arrival', [AdministrativeOfficerController::class, 'recordLocatorArrival'])->name('admin-officer.locator.record-arrival');
});

// Shared dashboard API endpoints (accessible by both department-head and administrative-officer)
Route::middleware(['auth', 'throttle:api', 'role:department-head,administrative-officer'])->group(function () {
    Route::get('/api/department/dashboard-metrics', [DepartmentHeadController::class, 'dashboardMetrics'])->name('api.department.dashboard-metrics');
    Route::get('/api/department/kpis', [DepartmentHeadController::class, 'dashboardMetrics'])->name('api.department.kpis');
    Route::get('/api/department/employees-on-duty', [DepartmentHeadController::class, 'employeesOnDuty'])->name('api.department.employees-on-duty');
    Route::get('/api/department/leave-requests', [DepartmentHeadController::class, 'leaveRequestsList'])->name('api.department.leave-requests');
    Route::get('/api/department/locator-requests', [DepartmentHeadController::class, 'locatorRequestsList'])->name('api.department.locator-requests');
    Route::get('/api/department/eta-requests', [DepartmentHeadController::class, 'etaRequestsList'])->name('api.department.eta-requests');
    // Travel & Office Order API endpoints (shared)
    Route::get('/api/department-employees', [TravelOrderController::class, 'getDepartmentEmployees'])->name('api.department-employees');
    Route::post('/api/travel-orders', [TravelOrderController::class, 'store'])->name('api.travel-orders');
    Route::get('/api/department/travel-orders', [TravelOrderController::class, 'index'])->name('api.department.travel-orders');
    Route::get('/api/travel-orders/{id}', [TravelOrderController::class, 'show'])->name('api.travel-orders.show');
    Route::post('/api/office-orders', [OfficeOrderController::class, 'store'])->name('api.office-orders');
    Route::get('/api/department/office-orders', [OfficeOrderController::class, 'index'])->name('api.department.office-orders');
    Route::get('/api/office-orders/{id}', [OfficeOrderController::class, 'show'])->name('api.office-orders.show');
    Route::put('/api/office-orders/{id}', [OfficeOrderController::class, 'update'])->name('api.office-orders.update');
});

// Department Head and Administrative Officer shared actions
Route::middleware(['auth', 'role:department-head,administrative-officer'])->group(function () {
    // Office order page / file routes (not under throttle:api - a 429 here would be
    // saved by the browser as the .docx download and open as a "corrupted" file)
    Route::get('/office-orders/{id}/edit', [OfficeOrderController::class, 'edit'])->name('office-orders.edit');
    Route::get('/office-orders/{id}/print', [OfficeOrderController::class, 'print'])->name('office-orders.print');
    Route::get('/office-orders/{id}/word', [OfficeOrderController::class, 'downloadWord'])->name('office-orders.word');

    // Leave approval actions
    Route::post('/department-head/leave/{id}/approve', [DepartmentHeadController::class, 'approve'])->name('department-head.leave.approve');
    Route::post('/department-head/leave/{id}/reject', [DepartmentHeadController::class, 'reject'])->name('department-head.leave.reject');
    Route::post('/department-head/leave/{id}/allow-printing', [DepartmentHeadController::class, 'allowPrinting'])->name('department-head.leave.allow-printing');

    // ETA and Locator actions
    Route::post('/department-head/eta/{id}/approve', [DepartmentHeadController::class, 'approveEta'])->name('department-head.eta.approve');
    Route::post('/department-head/eta/{id}/reject', [DepartmentHeadController::class, 'rejectEta'])->name('department-head.eta.reject');

    Route::post('/department-head/locator/{id}/approve', [DepartmentHeadController::class, 'approveLocator'])->name('department-head.locator.approve');
    Route::post('/department-head/locator/{id}/reject', [DepartmentHeadController::class, 'rejectLocator'])->name('department-head.locator.reject');
    Route::post('/department-head/locator/{id}/record-arrival', [DepartmentHeadController::class, 'recordLocatorArrival'])->name('department-head.locator.record-arrival');
});

// Department Head -Leave Cancellation
Route::middleware(['auth', 'role:department-head,administrative-officer'])->group(function () {
    Route::get('/department-head/leave-cancellation-requests', [DepartmentHeadCancellationController::class, 'leaveCancellationRequests'])
        ->name('department-head.leave-cancellation-requests');
    Route::post('/department-head/leave/{id}/recommend-cancellation', [DepartmentHeadCancellationController::class, 'recommend'])
        ->name('department-head.leave.recommend-cancellation');
    Route::post('/department-head/leave/{id}/reject-cancellation-dh', [DepartmentHeadCancellationController::class, 'reject'])
        ->name('department-head.leave.reject-cancellation');
    Route::get('/api/department-head/pending-cancellation-count', [DepartmentHeadCancellationController::class, 'pendingCancellationCount'])
        ->name('api.department-head.pending-cancellation-count');
});

// Administrative Officer -Leave Cancellation
Route::middleware(['auth', 'role:administrative-officer'])->group(function () {
    Route::get('/admin-officer/leave-cancellation-requests', [AdministrativeOfficerCancellationController::class, 'leaveCancellationRequests'])
        ->name('admin-officer.leave-cancellation-requests');
    Route::post('/admin-officer/leave/{id}/endorse-cancellation', [AdministrativeOfficerCancellationController::class, 'endorse'])
        ->name('admin-officer.leave.endorse-cancellation');
    Route::post('/admin-officer/leave/{id}/reject-cancellation', [AdministrativeOfficerCancellationController::class, 'reject'])
        ->name('admin-officer.leave.reject-cancellation');
    Route::get('/api/admin-officer/pending-cancellation-count', [AdministrativeOfficerCancellationController::class, 'pendingCancellationCount'])
        ->name('api.admin-officer.pending-cancellation-count');
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

    Route::get('/leave-manager/approved-leaves', [LeaveManagerController::class, 'approvedLeaves'])
        ->name('leave-manager.approved-leaves');

    Route::get('/leave-manager/employee-cancellation-requests', [LeaveManagerController::class, 'employeeCancellationRequests'])
        ->name('leave-manager.employee-cancellation-requests');

    Route::post('/api/leave/{leave}/approve-cancellation', [LeaveManagerController::class, 'apiApproveCancellation'])->name('api.leave.approve-cancellation');
    Route::post('/api/leave/{leave}/reject-cancellation', [LeaveManagerController::class, 'apiRejectCancellation'])->name('api.leave.reject-cancellation');
    Route::post('/api/leave/bulk-approve-cancellations', [LeaveManagerController::class, 'apiBulkApproveCancellation'])->name('api.leave.bulk-approve-cancellations');
    Route::post('/api/leave/bulk-reject-cancellations', [LeaveManagerController::class, 'apiBulkRejectCancellation'])->name('api.leave.bulk-reject-cancellations');
    Route::get('/api/leave-manager/pending-cancellation-count', [LeaveManagerController::class, 'apiPendingCancellationCount'])
        ->name('api.leave-manager.pending-cancellation-count');
    Route::post('/api/leave-manager/notify-dept-head', [LeaveManagerController::class, 'apiNotifyDeptHead'])
        ->name('api.leave-manager.notify-dept-head');
    Route::get('/api/employee-search', [LeaveManagerController::class, 'employeeSearch'])
        ->name('api.employee.search');

    Route::get('/leave-manager/leave-ledger', [LeaveManagerController::class, 'leaveLedger'])
        ->name('leave-manager.leave-ledger');

    Route::post('/leave-manager/leave-ledger/run-monthly-credits', [LeaveManagerController::class, 'runMonthlyCredits'])
        ->name('leave-manager.run-monthly-credits');

    Route::post('/leave-manager/leave-ledger/recompute-employee-month', [LeaveManagerController::class, 'recomputeEmployeeMonth'])
        ->name('leave-manager.recompute-employee-month');

    Route::get('/leave-manager/leave-card/download', [LeaveManagerController::class, 'downloadLeaveCard'])
        ->name('leave-manager.leave-card.download');

    // Uniform Inspection Management
    Route::get('/leave-manager/uniform-inspections', [UniformInspectionController::class, 'index'])
        ->name('leave-manager.uniform-inspections.index');
    Route::get('/leave-manager/uniform-inspections/create', [UniformInspectionController::class, 'create'])
        ->name('leave-manager.uniform-inspections.create');
    Route::post('/leave-manager/uniform-inspections', [UniformInspectionController::class, 'store'])
        ->name('leave-manager.uniform-inspections.store');
    Route::get('/leave-manager/uniform-inspections/{uniformInspection}', [UniformInspectionController::class, 'show'])
        ->name('leave-manager.uniform-inspections.show');
    Route::get('/leave-manager/uniform-inspections/{uniformInspection}/edit', [UniformInspectionController::class, 'edit'])
        ->name('leave-manager.uniform-inspections.edit');
    Route::put('/leave-manager/uniform-inspections/{uniformInspection}', [UniformInspectionController::class, 'update'])
        ->name('leave-manager.uniform-inspections.update');
    Route::delete('/leave-manager/uniform-inspections/{uniformInspection}', [UniformInspectionController::class, 'destroy'])
        ->name('leave-manager.uniform-inspections.destroy');

    Route::get('/api/uniform-inspection/employee-history', [UniformInspectionController::class, 'apiEmployeeViolationHistory'])
        ->name('api.uniform-inspection.employee-history');

    // Attendance Adjustment Summary review - Leave Manager acts on submissions
    // forwarded from the Timekeeper/HR Manager screen (attendance.adjustment-summary.*)
    Route::get('/leave-manager/attendance-deductions', [AttendanceAdjustmentReviewController::class, 'index'])
        ->name('leave-manager.attendance-deductions');
    Route::post('/api/leave-manager/attendance-deductions/{item}/deduct', [AttendanceAdjustmentReviewController::class, 'apiDeduct'])
        ->name('api.leave-manager.attendance-deductions.deduct');
    Route::post('/api/leave-manager/attendance-deductions/{item}/dismiss', [AttendanceAdjustmentReviewController::class, 'apiDismiss'])
        ->name('api.leave-manager.attendance-deductions.dismiss');
    Route::post('/api/leave-manager/attendance-deductions/bulk-deduct', [AttendanceAdjustmentReviewController::class, 'apiBulkDeduct'])
        ->name('api.leave-manager.attendance-deductions.bulk-deduct');
    Route::post('/api/leave-manager/attendance-deductions/bulk-dismiss', [AttendanceAdjustmentReviewController::class, 'apiBulkDismiss'])
        ->name('api.leave-manager.attendance-deductions.bulk-dismiss');
});

Route::middleware(['auth', 'role:leave-manager,hr-manager'])->group(function () {
    Route::get('/api/leave-ledger/history', [LeaveManagerController::class, 'apiLedgerHistory'])
        ->name('api.leave-ledger.history');
    Route::get('/api/leave-ledger/monthly', [LeaveManagerController::class, 'apiMonthlyCredits'])
        ->name('api.leave-ledger.monthly');
    Route::get('/api/leave-ledger/awol-monitor', [LeaveManagerController::class, 'apiAwolMonitor'])
        ->name('api.leave-ledger.awol-monitor');
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
    Route::redirect('/dashboard/hr-manager/reports', '/dashboard/hr-manager');
    Route::get('/dashboard/hr-manager/audit', [HRManagerController::class, 'audit'])
        ->name('hr-manager.audit');
    Route::get('/dashboard/hr-manager/roles', [HRManagerController::class, 'roles'])
        ->name('hr-manager.roles');
    Route::get('/dashboard/hr-manager/settings', [HRManagerController::class, 'settings'])
        ->name('hr-manager.settings');
    Route::post('/dashboard/hr-manager/settings', [HRManagerController::class, 'updateSettings'])
        ->name('hr-manager.settings.update');
    Route::get('/dashboard/hr-manager/settings/backup', [HRManagerController::class, 'backupDatabase'])
        ->name('hr-manager.settings.backup');
    Route::post('/dashboard/hr-manager/settings/restore', [HRManagerController::class, 'restoreDatabase'])
        ->name('hr-manager.settings.restore')
        ->withoutMiddleware(LimitPayloadSize::class);

    Route::get('/dashboard/hr-manager/records/data', [HRManagerController::class, 'recordsData'])
        ->name('hr-manager.records.data');
    Route::post('/dashboard/hr-manager/records/{user}/action', [HRManagerController::class, 'recordsAction'])
        ->name('hr-manager.records.action');
    Route::put('/dashboard/hr-manager/records/{user}', [HRManagerController::class, 'recordsUpdate'])
        ->name('hr-manager.records.update');
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
    Route::get('/dashboard/hr-manager/audit/data', [HRManagerController::class, 'auditData'])
        ->name('hr-manager.audit.data');
    Route::get('/dashboard/hr-manager/employees/filter', [HRManagerController::class, 'getEmployeesByFilter'])
        ->name('hr-manager.employees.filter');

    // Enhancement 1: Alerts
    Route::get('/dashboard/hr-manager/alerts', [HRManagerController::class, 'getAlerts'])
        ->name('hr-manager.alerts');

    // Enhancement 2: Attendance Overview
    Route::get('/dashboard/hr-manager/attendance-overview', [HRManagerController::class, 'attendanceOverview'])
        ->name('hr-manager.attendance.overview');
    Route::get('/dashboard/hr-manager/attendance-overview/data', [HRManagerController::class, 'attendanceOverviewData'])
        ->name('hr-manager.attendance.overview.data');
    Route::post('/dashboard/hr-manager/attendance-overview/notify-dept-head', [HRManagerController::class, 'attendanceNotifyDeptHead'])
        ->name('hr-manager.attendance.notify-dept-head');

    // Enhancement 3: Leave Analytics
    Route::get('/dashboard/hr-manager/leave/analytics', [HRManagerController::class, 'getLeaveAnalytics'])
        ->name('hr-manager.leave.analytics');
    Route::post('/dashboard/hr-manager/leave/notify-manager', [HRManagerController::class, 'notifyDeptManager'])
        ->name('hr-manager.leave.notify-manager');

    // Enhancement 4: Payroll Overview
    Route::get('/dashboard/hr-manager/payroll-overview', [HRManagerController::class, 'payrollOverview'])
        ->name('hr-manager.payroll.overview');
    Route::get('/dashboard/hr-manager/payroll-overview/data', [HRManagerController::class, 'payrollOverviewData'])
        ->name('hr-manager.payroll.overview.data');
    Route::post('/dashboard/hr-manager/payroll-exceptions/{exception}/resolve', [HRManagerController::class, 'resolvePayrollException'])
        ->name('hr-manager.payroll.exception.resolve');

    // Enhancement 5: Workforce Planning
    Route::get('/dashboard/hr-manager/records/planning-data', [HRManagerController::class, 'recordsPlanningData'])
        ->name('hr-manager.records.planning-data');

    Route::get('/dashboard/hr-manager/service-milestones', [HRManagerController::class, 'serviceMilestones'])
        ->name('hr-manager.service-milestones');

    Route::get('/dashboard/hr-manager/leave-ledger', [HRManagerController::class, 'leaveLedger'])
        ->name('hr-manager.leave-ledger');

    Route::get('/dashboard/hr-manager/leave-card/download', [HRManagerController::class, 'downloadLeaveCard'])
        ->name('hr-manager.leave-card.download');

});

Route::middleware(['auth', 'role:hr-manager,time-keeper'])->group(function () {
    Route::get('/dashboard/hr-manager/attendance/import', [AttendanceImportController::class, 'index'])
        ->name('hr-manager.attendance.import');
    Route::post('/dashboard/hr-manager/attendance/import', [AttendanceImportController::class, 'store'])
        ->name('hr-manager.attendance.import.store');
});

// DTR Excuse management (HR Manager, Administrative Officer, Department Head)
Route::middleware(['auth', 'role:hr-manager,administrative-officer,department-head'])->group(function () {
    Route::get('/attendance/dtr-excuse', [DtrExcuseController::class, 'index'])
        ->name('attendance.dtr-excuse.index');
    Route::post('/attendance/dtr-excuse', [DtrExcuseController::class, 'store'])
        ->name('attendance.dtr-excuse.store');
    Route::post('/attendance/dtr-excuse/check', [DtrExcuseController::class, 'check'])
        ->name('attendance.dtr-excuse.check');
    Route::delete('/attendance/dtr-excuse/{dtrExcuse}', [DtrExcuseController::class, 'destroy'])
        ->name('attendance.dtr-excuse.destroy');
});

// Work-shift templates + assignment (Time Keeper / HR Manager unrestricted;
// Administrative Officer / Department Head scoped to their own department and
// gated by an explicit ShiftManagementGrant - see ShiftController/
// EmployeeScheduleController/ShiftScheduleController for the finer-grained
// per-action gating, e.g. Shift Templates write actions stay
// Time-Keeper/HR-Manager-only even though the read route is shared).
Route::middleware(['auth', 'role:time-keeper,hr-manager,administrative-officer,department-head'])->group(function () {
    Route::get('/attendance/shifts', [ShiftController::class, 'index'])
        ->name('attendance.shifts');
    Route::post('/attendance/shifts', [ShiftController::class, 'store'])
        ->name('attendance.shifts.store');
    Route::put('/attendance/shifts/{shift}', [ShiftController::class, 'update'])
        ->name('attendance.shifts.update');
    Route::delete('/attendance/shifts/{shift}', [ShiftController::class, 'destroy'])
        ->name('attendance.shifts.destroy');

    Route::get('/attendance/schedules', [EmployeeScheduleController::class, 'index'])
        ->name('attendance.schedules');
    Route::put('/attendance/schedules/bulk-assign', [EmployeeScheduleController::class, 'bulkAssign'])
        ->name('attendance.schedules.bulk-assign');
    Route::get('/attendance/schedules/{user}/history', [EmployeeScheduleController::class, 'history'])
        ->name('attendance.schedules.history');
    Route::get('/attendance/schedules/{user}/resolved', [EmployeeScheduleController::class, 'resolved'])
        ->name('attendance.schedules.resolved');
    Route::put('/attendance/schedules/{user}', [EmployeeScheduleController::class, 'update'])
        ->name('attendance.schedules.update');
    Route::put('/attendance/schedules/{user}/exempt', [EmployeeScheduleController::class, 'toggleExempt'])
        ->name('attendance.schedules.exempt');

    Route::get('/attendance/shift-schedule', [ShiftScheduleController::class, 'index'])
        ->name('attendance.shift-schedule.index');
    Route::post('/attendance/shift-schedule', [ShiftScheduleController::class, 'store'])
        ->name('attendance.shift-schedule.store');
    Route::post('/attendance/shift-schedule/store-bulk', [ShiftScheduleController::class, 'storeBulk'])
        ->name('attendance.shift-schedule.store-bulk');
    Route::post('/attendance/shift-schedule/apply-weekly-pattern', [ShiftScheduleController::class, 'applyWeeklyPattern'])
        ->name('attendance.shift-schedule.apply-weekly-pattern');
    Route::post('/attendance/shift-schedule/generate-pattern', [ShiftScheduleController::class, 'generatePattern'])
        ->name('attendance.shift-schedule.generate-pattern');
    Route::post('/attendance/shift-schedule/generate-pattern-bulk', [ShiftScheduleController::class, 'generatePatternBulk'])
        ->name('attendance.shift-schedule.generate-pattern-bulk');
});

// Workforce Calendar - read-only "who's away" planning view (Time Keeper / HR
// Manager unrestricted; Administrative Officer / Department Head scoped to
// their own department, no ShiftManagementGrant gate since it's read-only).
Route::middleware(['auth', 'role:time-keeper,hr-manager,administrative-officer,department-head'])->group(function () {
    Route::get('/attendance/workforce-calendar', [WorkforceCalendarController::class, 'index'])
        ->name('attendance.workforce-calendar.index');
});

// Shift Management access grants + company-wide Shift Logs (Time Keeper / HR Manager only)
Route::middleware(['auth', 'role:time-keeper,hr-manager'])->group(function () {
    Route::get('/attendance/shift-access', [ShiftManagementAccessController::class, 'index'])
        ->name('attendance.shift-access.index');
    Route::post('/attendance/shift-access/{department}/grant', [ShiftManagementAccessController::class, 'grant'])
        ->name('attendance.shift-access.grant');
    Route::post('/attendance/shift-access/{department}/revoke', [ShiftManagementAccessController::class, 'revoke'])
        ->name('attendance.shift-access.revoke');

    Route::get('/attendance/shift-logs', [ShiftLogController::class, 'index'])
        ->name('attendance.shift-logs');

    Route::get('/attendance/time-logs-monitoring', [TimeLogsMonitoringController::class, 'index'])
        ->name('attendance.time-logs-monitoring');

    Route::get('/attendance/monitoring-matrix', [TimeLogsMonitoringController::class, 'monitoringMatrix'])
        ->name('attendance.monitoring-matrix');

    Route::get('/attendance/adjustment-summary', [AttendanceAdjustmentSummaryController::class, 'index'])
        ->name('attendance.adjustment-summary.index');
    Route::get('/attendance/adjustment-summary/data', [AttendanceAdjustmentSummaryController::class, 'data'])
        ->name('attendance.adjustment-summary.data');
    Route::post('/attendance/adjustment-summary/submit', [AttendanceAdjustmentSummaryController::class, 'submit'])
        ->name('attendance.adjustment-summary.submit');
    Route::get('/attendance/adjustment-summary/print', [AttendanceAdjustmentSummaryController::class, 'print'])
        ->name('attendance.adjustment-summary.print');
    Route::get('/attendance/adjustment-summary/pdf', [AttendanceAdjustmentSummaryController::class, 'pdf'])
        ->name('attendance.adjustment-summary.pdf');
    Route::get('/attendance/adjustment-summary/submissions', [AttendanceAdjustmentSummaryController::class, 'submissions'])
        ->name('attendance.adjustment-summary.submissions');
    Route::get('/attendance/adjustment-summary/submissions/{submission}/items', [AttendanceAdjustmentSummaryController::class, 'submissionItems'])
        ->name('attendance.adjustment-summary.submissions.items');
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
    Route::get('plantilla-reports', [PlantillaController::class, 'reports'])->name('plantilla.reports');
    Route::get('plantilla-service-trail', [PlantillaController::class, 'serviceTrail'])->name('plantilla.service-trail');
    Route::post('plantilla-history', [EmployeeAssignmentController::class, 'storeHistorical'])->name('plantilla.history.store');
    Route::post('plantilla-promote', [EmployeeAssignmentController::class, 'promote'])->name('plantilla.promote');
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
    Route::post('salary-matrix-versions', [SalaryMatrixController::class, 'storeVersion'])->name('salary-matrix.versions.store');
    Route::resource('earnings', EarningsController::class)->names([
        'index' => 'earnings.index',
        'create' => 'earnings.create',
        'store' => 'earnings.store',
        'show' => 'earnings.show',
        'edit' => 'earnings.edit',
        'update' => 'earnings.update',
        'destroy' => 'earnings.destroy',
    ]);
    Route::post('earnings/{earning}/assignments', [EmployeeEarningController::class, 'store'])->name('earnings.assignments.store');
    Route::put('earnings/{earning}/assignments/{assignment}', [EmployeeEarningController::class, 'update'])->name('earnings.assignments.update');
    Route::delete('earnings/{earning}/assignments/{assignment}', [EmployeeEarningController::class, 'destroy'])->name('earnings.assignments.destroy');
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

// Development-only: preview leave status email in browser (local + authenticated only)
Route::get('/dev/preview-leave-email', [DevController::class, 'previewLeaveEmail'])
    ->middleware('auth')
    ->name('dev.preview.leave.email');
