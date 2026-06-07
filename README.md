# HRIS — LGU Calapan

Human Resource Information System for LGU Calapan. Manages employee records, leave requests, travel orders, document requests, payroll, and biometric attendance — built on Laravel 12 / PHP 8.4, running entirely in Docker.

## Requirements

- Docker Desktop (or Docker Engine + Compose plugin)
- No local PHP or Node installation needed

## Quick start

```bash
# Start the full dev stack (app, nginx, MySQL, queue worker, Vite, Mailpit, phpMyAdmin)
docker compose -f compose.dev.yaml up --build

# Stop (preserves database volume)
docker compose -f compose.dev.yaml down

# Stop and wipe all data
docker compose -f compose.dev.yaml down -v
```

| Service     | URL                      |
|-------------|--------------------------|
| App         | http://localhost:8090    |
| Vite HMR    | http://localhost:5173    |
| phpMyAdmin  | http://localhost:8091    |
| Mailpit UI  | http://localhost:8025    |

## Environment setup

Copy `.env.example` to `.env` and set `APP_KEY`:

```bash
cp .env.example .env
docker exec hris-dev-app php artisan key:generate
```

## Database connection — inside vs outside Docker

The `DB_HOST` value depends on **where the code runs**, not where MySQL runs.

### Inside the container

The app container and the MySQL container share a Docker network. MySQL is reachable via its service name. `compose.dev.yaml` injects this directly into the container environment, overriding whatever `.env` says:

```dotenv
DB_HOST=db      # injected by compose.dev.yaml — no .env change needed
DB_PORT=3306
```

### Outside the container (host machine)

When running `php artisan migrate`, Tinker, or a local DB client **directly on your host machine**, traffic must go through the port Docker exposes. `compose.dev.yaml` maps MySQL as `3306:3306`, and `.env` ships with these values already set:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
```

`.env` ships with `DB_HOST=127.0.0.1` for this reason. `compose.dev.yaml` injects `DB_HOST=db` directly into the container environment, overriding `.env` for container runs — so the file value is only ever used by host-side tools.

## Running artisan commands

Two workflows — pick the one that matches where your terminal is:

**Option A — inside the container (recommended)**

```bash
# compose.dev.yaml sets DB_HOST=db automatically; no .env change needed.
docker exec hris-dev-app php artisan migrate
docker exec hris-dev-app php artisan queue:work
docker exec hris-dev-app php artisan tinker
```

**Option B — directly on the host machine**

```bash
# .env already has DB_HOST=127.0.0.1 and DB_PORT=3306, which routes through
# the port Docker exposes to the host (compose.dev.yaml maps 3306:3306).
# The stack must be running first:
docker compose -f compose.dev.yaml up -d
php artisan migrate
```

## Running tests

Tests require a `HRIS_test` database on the running `hris-dev-db` container (created automatically by the test suite seeder):

```bash
docker exec hris-dev-app php artisan test

# Single file
docker exec hris-dev-app php artisan test tests/Feature/RoleBased/LeaveManagerTest.php

# Single method
docker exec hris-dev-app php artisan test --filter=test_method_name
```

## Code style

```bash
docker exec hris-dev-app vendor/bin/pint
```
