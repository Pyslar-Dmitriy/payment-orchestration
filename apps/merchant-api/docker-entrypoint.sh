#!/bin/sh
set -e

cd /var/www/html

# Re-generate framework caches at startup so they reflect the
# runtime environment (DB_HOST, APP_KEY, etc.) rather than build-time values.
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec php-fpm