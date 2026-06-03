#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p \
  storage/app/uploads \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache public || true

# Ensure the default local strategy symlink exists when storage is mounted.
if [ ! -e public/i ]; then
  ln -s ../storage/app/uploads public/i 2>/dev/null || true
fi

# Runtime env is injected by docker compose/Jenkins, so Laravel caches happen here, not at image build time.
if [ "${LARAVEL_OPTIMIZE:-true}" = "true" ]; then
  php artisan config:clear >/dev/null 2>&1 || true
  php artisan route:clear >/dev/null 2>&1 || true
  php artisan view:clear >/dev/null 2>&1 || true
  php artisan config:cache >/dev/null 2>&1 || true
  php artisan route:cache >/dev/null 2>&1 || true
  php artisan view:cache >/dev/null 2>&1 || true
fi

exec "$@"
