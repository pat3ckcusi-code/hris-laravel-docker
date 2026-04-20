#!/bin/sh
set -e

cd /var/www/html

# Ensure writable dirs exist (named volumes mount empty on first run)
mkdir -p storage/app storage/logs \
         storage/framework/cache storage/framework/sessions storage/framework/views \
         bootstrap/cache

# Seed bundled templates only when the host-mounted directory is empty (first run).
# After that, the host directory is the source of truth — update templates there.
if [ -d /opt/app-templates ]; then
  mkdir -p storage/app/templates
  if [ -z "$(ls -A storage/app/templates 2>/dev/null)" ]; then
    cp -r /opt/app-templates/* storage/app/templates/
    echo "[entrypoint] Seeded templates into empty storage/app/templates/"
  else
    echo "[entrypoint] storage/app/templates/ already has files — skipping seed"
  fi
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Persist APP_KEY across restarts: store in the volume when generated,
# reload from the volume on subsequent boots.
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

# Rebuild caches against the live environment (env vars from compose)
php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
