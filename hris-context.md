# HRIS Context

## AdministrativeOfficerController
- Routes: /admin-officer (dashboard), /admin-officer/pending-requests, /admin-officer/approved-requests, /admin-officer/statistics, /admin-officer/approve/{id}, etc.
- Schema: leave_requests, eta, locator, users, departments, hr_audit_trails
- Goals: Manage department-level approvals, track printing authorization, exclude dept head requests, maintain audit trail

## DashboardController
- Routes: /dashboard, /employee/pds, /records-manager/departments, etc.
- Schema: users, pds, departments, hr_audit_trails
- Goals: Role-based dashboards, manage PDS, maintain department hierarchy, synchronize name fields

## DepartmentHeadController
- Routes: /department-head (dashboard, pending, approved, statistics, employees-on-duty, etc.)
- Schema: leave_requests, leave_dates, eta, locator, users, hr_audit_trails
- Goals: Manage approvals, track on-duty status, generate statistics, printing pre-approval workflow

## DocumentRequestController
- Routes: /documents (history, store, preview, print)
- Schema: document_requests, document_types, users
- Goals: Employee document requests, support templates, track lifecycle

## DocumentSettingsController
- Routes: /document-settings (list, create, edit, update, delete)
- Schema: document_types, parts JSON
- Goals: Create/manage templates, support rich formatting, multi-signatory docs

## FrontDeskController
- Routes: /front-desk (dashboard, pending, approved, complete, print, report)
- Schema: document_requests, users, departments
- Goals: Process document requests, track workflow, send notifications, generate reports

## HRManagerController
- Routes: /hr-manager (dashboard, records, leave, frontdesk, reports, audit, settings)
- Schema: users, pds, leave_requests, document_requests, hr_audit_trails, settings
- Goals: Analytics dashboard, manage records, approve/reject leave, track documents, audit logs, configure system

## LeaveManagerController
- Routes: /leave-manager (manage balance, credits, cancellations, holidays)
- Schema: leave_balances, leave_requests, leave_dates, holidays, users
- Goals: Manage balances, process cancellations, auto-cancel holidays, granular deductions, audit trail

## LeaveRequestController
- Routes: /leave-requests (history, store, view, print, approve, cancel)
- Schema: leave_requests, leave_dates, leave_balances, users, departments
- Goals: File multi-date requests, enforce advance notice, auto-deduct balances, route approvals, support cancellations

## MayorController
- Routes: /mayor (dashboard, approvals, travel orders, reports, policies, employees, events, settings)
- Schema: leave_requests, travel_orders, travel_order_employees, users, pds
- Goals: Approve dept head/HR manager leaves, manage travel orders, city-wide analytics, final approval authority

## OfficeOrderController
- Routes: /office-orders (list, view, store)
- Schema: office_orders, office_order_employees, users
- Goals: Track office orders, link to employees, filter by department/status

## PDFController
- Status: Empty template for PDF utilities

## RecordsManagerController
- Routes: /records-manager (employees CRUD, reset password)
- Schema: users, departments
- Goals: CRUD employee records, account creation, duplicate detection, password reset, role assignment

## TravelOrderController
- Routes: /travel-orders (employees, list, view, store)
- Schema: travel_orders, travel_order_employees, users
- Goals: Create travel orders, track status, link to employees

## UserController
- Routes: /user/change-password
- Schema: users
- Goals: Change password, enforce confirmation/min length, prevent reuse
