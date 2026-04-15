#!/bin/sh
set -e

cd /var/www/html

# Ensure writable dirs exist (named volumes mount empty on first run)
mkdir -p storage/app storage/logs \
         storage/framework/cache storage/framework/sessions storage/framework/views \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Rebuild caches against the live environment (env vars from compose)
php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
