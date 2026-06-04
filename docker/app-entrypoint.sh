#!/bin/sh
set -e

cd /var/www/html

# Ensure writable dirs exist (named volumes mount empty on first run)
mkdir -p storage/app storage/app/public storage/logs \
         storage/framework/cache storage/framework/sessions storage/framework/views \
         bootstrap/cache

# Seed bundled templates only when the host-mounted directory is empty (first run).
# Guard against an empty /opt/app-templates (no bundled templates) so the glob
# doesn't fail under `set -e` and crash the container.
if [ -d /opt/app-templates ] && [ -n "$(ls -A /opt/app-templates 2>/dev/null)" ]; then
  mkdir -p storage/app/templates
  if [ -z "$(ls -A storage/app/templates 2>/dev/null)" ]; then
    cp -r /opt/app-templates/. storage/app/templates/ 2>/dev/null || true
    echo "[entrypoint] Seeded templates into empty storage/app/templates/"
  else
    echo "[entrypoint] storage/app/templates/ already has files — skipping seed"
  fi
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Create the public/storage → storage/app/public symlink
php artisan storage:link --force 2>/dev/null || true

# Persist APP_KEY across restarts.
APP_KEY_FILE="storage/app/.app_key"
if [ -z "$APP_KEY" ]; then
  if [ -f "$APP_KEY_FILE" ]; then
    APP_KEY=$(cat "$APP_KEY_FILE")
    export APP_KEY
    echo "[entrypoint] Loaded persisted APP_KEY from volume"
  else
    echo "[entrypoint] APP_KEY is empty — generating one..."
    APP_KEY=$(php artisan key:generate --show)
    export APP_KEY
    echo "$APP_KEY" > "$APP_KEY_FILE"
    chmod 600 "$APP_KEY_FILE"
    chown www-data:www-data "$APP_KEY_FILE"
    echo "[entrypoint] Generated and persisted APP_KEY"
  fi
fi

if [ "${APP_ENV:-production}" = "local" ]; then
  echo "[entrypoint] Dev mode — clearing caches and running migrations..."
  php artisan config:clear  >/dev/null 2>&1 || true
  php artisan route:clear   >/dev/null 2>&1 || true
  php artisan view:clear    >/dev/null 2>&1 || true
  php artisan migrate --force 2>&1 || true
else
  echo "[entrypoint] Production mode — building caches..."
  php artisan config:clear  >/dev/null 2>&1 || true
  php artisan config:cache  >/dev/null 2>&1 || true
  php artisan route:cache   >/dev/null 2>&1 || true
  php artisan view:cache    >/dev/null 2>&1 || true
fi

exec "$@"
