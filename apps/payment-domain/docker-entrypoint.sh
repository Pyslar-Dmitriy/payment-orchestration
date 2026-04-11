#!/bin/sh
set -e

cd /var/www/html

composer update

# In production, cache config/routes/events so they are served from a single
# compiled PHP file instead of being parsed on every request.
# In local dev the source directories are bind-mounted from the host, so any
# cache built at startup becomes stale the moment a file changes.  Skip all
# caching and actively clear any leftovers from a previous production build.
if [ "${APP_ENV}" != "local" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
else
    php artisan optimize:clear
fi

exec php-fpm
