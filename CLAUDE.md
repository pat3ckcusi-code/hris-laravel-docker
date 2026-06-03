# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **HRIS** (Human Resource Information System) for LGU Calapan — a Laravel 12 / PHP 8.4 web application running in Docker. It manages employee records, leave requests, travel orders, document requests, payroll, and role-based workflows for a local government unit.

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

### Audit trail
All approval/rejection actions write to `hr_audit_trails`. Leave, ETA, and locator tables each have approval audit columns added via dedicated migrations.

### Services layer
Business logic is extracted into `app/Services/`:
- `LeaveRequestService` — balance deduction, multi-date handling
- `PayrollComputationService` — full payroll calculation
- `DepartmentHeadService` / `DepartmentService` — org hierarchy
- `PdsService` — Personal Data Sheet export
- `LocatorExportService` — locator slip Excel export

### Testing
Tests live in `tests/Feature/` organized by concern:
- `RoleBased/` — one test file per role (EmployeeTest, MayorTest, etc.)
- `CrossCutting/` — security, authentication, DB performance, error handling
- `ISO25010/` and `Performance/` — quality and load tests

Tests use a real MySQL database (`HRIS_test`) — do not mock the database driver.

### Frontend asset organization
Vite is configured with per-module entry points (e.g. `hr_manager.css`/`hr_manager.js`, `front_desk.css`/`front_desk.js`). Output goes to `public/build/assets/css/` and `public/build/assets/js/`. CSS-entry loader JS files land in `assets/css-loaders/` to avoid name collisions.
