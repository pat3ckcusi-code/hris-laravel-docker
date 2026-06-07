# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **HRIS** (Human Resource Information System) for LGU Calapan — a Laravel 12 / PHP 8.4 web application running in Docker. It manages employee records, leave requests, travel orders, document requests, payroll, attendance/DTR tracking, and role-based workflows for a local government unit.

## Development Environment

The project runs entirely in Docker. There is no local PHP/Node setup.

### Start local dev stack
```bash
docker compose -f compose.dev.yaml up --build
```

### Stop (keep volumes)
```bash
docker compose -f compose.dev.yaml down
```

### Stop and remove all volumes
```bash
docker compose -f compose.dev.yaml down -v
```

### Dev service URLs
| Service     | URL                        |
|-------------|---------------------------|
| App (nginx) | http://localhost:8090      |
| Vite HMR    | http://localhost:5173      |
| phpMyAdmin  | http://localhost:8091      |
| Mailpit UI  | http://localhost:8025      |

### Run artisan commands
```bash
docker exec hris-dev-app php artisan <command>
```

### Run tests (requires running `hris-dev-db` with a `HRIS_test` database)
```bash
docker exec hris-dev-app php artisan test
# Run a single test file
docker exec hris-dev-app php artisan test tests/Feature/RoleBased/LeaveManagerTest.php
# Run a specific test method
docker exec hris-dev-app php artisan test --filter=test_method_name
```

### Code style (Laravel Pint)
```bash
docker exec hris-dev-app vendor/bin/pint
```

### Build frontend assets
```bash
# Inside container (Vite container handles this in dev)
npm run build
```

## Architecture

### Stack
- **Backend**: Laravel 12, PHP 8.4-FPM
- **Frontend**: Blade templates + Tailwind CSS v4 (via `@tailwindcss/vite`) + per-module CSS/JS bundles compiled by Vite
- **Database**: MySQL 8.0 — session, cache, and queue all stored in the database
- **PDF generation**: `barryvdh/laravel-dompdf`; Excel exports via `maatwebsite/excel` / `phpoffice/phpspreadsheet`
- **Queue**: Database-backed, processed by a dedicated `queue` container

### Docker image stages (Dockerfile)
1. **assets** — Node 20, runs `vite build`, outputs `public/build/`
2. **app** — PHP 8.4-FPM, Composer deps, copies compiled assets from stage 1. Entrypoint (`docker/app-entrypoint.sh`) runs migrations in dev mode and builds caches in production.
3. **nginx** — nginx:stable-alpine, bakes in `public/` from stage 1

### Role-based access
Authorization is a single `access_level` column on the `users` table. The `EnsureRole` middleware (`app/Http/Middleware/EnsureRole.php`) enforces it per route group. Roles map directly to controllers:

| Role                  | Controller                        |
|-----------------------|-----------------------------------|
| employee              | LeaveRequestController, EtaController, LocatorController, EmployeePayslipController |
| department head       | DepartmentHeadController          |
| administrative officer| AdministrativeOfficerController   |
| hr manager            | HRManagerController               |
| leave manager         | LeaveManagerController            |
| records manager       | RecordsManagerController          |
| front desk            | FrontDeskController               |
| mayor                 | MayorController                   |
| payroll officer       | Payroll/* controllers             |

The `DenyJobOrder` middleware blocks job-order employees from leave filing. `ForcePasswordChange` redirects on first login.

### Key domain flows

**Leave requests**: Employee files → Department Head approves → Administrative Officer pre-approves for printing → HR Manager has override authority. `LeaveRequestService` handles multi-date deductions and balance management. `HolidayLeaveCancellationService` auto-cancels leave falling on holidays.

**Document requests**: Employee requests → Front Desk processes → status email sent via queue. `DocumentType` stores template definitions with a `parts` JSON column for rich formatting and multi-signatory layouts.

**Payroll**: Plantilla → Salary matrix → Payroll run → earnings/deductions computed by `PayrollComputationService` → payslips generated → Mayor/HR Manager approval workflow.

**Approval hierarchy**: Mayor is the final authority for Department Head and HR Manager leave; Department Heads handle subordinate employees; Administrative Officers handle department-level printing authorization.

**Attendance / DTR**: Raw biometric punches are imported from an external API into `attendance_logs`, then resolved into CSC Form 48 slots (AM in/out, PM in/out) in the `dtrs` table. The `dtrs.source` column distinguishes `'biometric'` (API-imported) from `'manual'` entries.

### Attendance module internals

The attendance pipeline has four layers:

1. **`IntegrationApiService`** — authenticates with the external biometric API and bulk-fetches punch logs page by page (one API call per 1 000 records instead of one per employee).
2. **`PersonnelLogImportService`** — writes raw punches into `attendance_logs` (unique on `user_id + logdate + logtime`) and calls `recomputeDtr` per affected employee.
3. **`DtrPunchResolver`** — converts a day's sorted punch list into the four CSC Form 48 time slots and computes `late_minutes` / `undertime_minutes`. Shared by the import service and the export service so they always agree.
4. **`Form48ExportService`** — fills the `storage/app/templates/form48.xls` template with resolved DTR data and produces a password-protected Excel download.

Entry points:
- **UI import**: `AttendanceImportController` dispatches `ImportAttendanceLogsJob` (queued, 10-minute timeout, 1 attempt).
- **Artisan recompute**: `php artisan dtr:recompute [--from=] [--to=] [--user=]` — rebuilds `dtrs` rows from existing `attendance_logs` without re-fetching the API.
- **DTR view / Form 48 download**: `DtrController` at `/attendance/dtr*` — role-branching in controller (admin sees all employees; employee sees own records only).

The EmpNo matching between the biometric system and HRIS handles both zero-padded (`02009`) and non-padded (`2009`) formats via a two-layer lookup (exact first, stripped fallback).

Env vars required for biometric integration (see `config/integration.php`):
```
INTEGRATION_API_BASE_URL=
INTEGRATION_API_USERNAME=
INTEGRATION_API_PASSWORD=
INTEGRATION_API_TOKEN_PATH=/api/Integration/GetToken
INTEGRATION_API_LOGS_PATH=/api/Integration/GetTimeLogsBulkData
INTEGRATION_API_TOKEN_TIMEOUT=15
INTEGRATION_API_LOGS_TIMEOUT=30
INTEGRATION_API_LOGS_PAGE_SIZE=1000
EXCEL_EXPORT_SHEET_PASSWORD=
EXCEL_EXPORT_PROTECTION_ENABLED=true
```

### Audit trail
All approval/rejection actions write to `hr_audit_trails`. Leave, ETA, and locator tables each have approval audit columns added via dedicated migrations. The attendance import job also writes an `attendance_import` audit entry on completion (success or failure).

### Services layer
Business logic is extracted into `app/Services/`:
- `LeaveRequestService` — balance deduction, multi-date handling
- `PayrollComputationService` — full payroll calculation
- `DepartmentHeadService` / `DepartmentService` — org hierarchy
- `PdsService` — Personal Data Sheet export
- `LocatorExportService` — locator slip Excel export
- `PersonnelLogImportService` — biometric punch import and DTR recomputation
- `DtrPunchResolver` — converts raw punches to Form 48 slots (shared by import and export)
- `Form48ExportService` — fills `form48.xls` template and streams a protected Excel file
- `IntegrationApiService` — external biometric API client (token + bulk log fetch)
- `RecordsService` — employee list and stats for the records manager

### Testing
Tests live in `tests/Feature/` organized by concern:
- `RoleBased/` — one test file per role (EmployeeTest, MayorTest, etc.)
- `CrossCutting/` — security, authentication, DB performance, error handling
- `ISO25010/` and `Performance/` — quality and load tests

Tests use a real MySQL database (`HRIS_test`) — do not mock the database driver.

### Frontend asset organization
Vite is configured with per-module entry points (e.g. `hr_manager.css`/`hr_manager.js`, `front_desk.css`/`front_desk.js`). Output goes to `public/build/assets/css/` and `public/build/assets/js/`. CSS-entry loader JS files land in `assets/css-loaders/` to avoid name collisions.
