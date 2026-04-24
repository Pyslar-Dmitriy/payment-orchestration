FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        git \
        curl \
        zip \
        unzip \
        libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql opcache pcntl bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize

RUN composer dump-autoload --optimize

FROM base AS local

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN echo 'opcache.enable=0' > /usr/local/etc/php/conf.d/opcache-disable.ini \
    && mkdir -p /var/www/html/storage/app/public \
               /var/www/html/storage/framework/cache/data \
               /var/www/html/storage/framework/sessions \
               /var/www/html/storage/framework/testing \
               /var/www/html/storage/framework/views \
               /var/www/html/storage/logs \
               /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM base AS production

COPY --from=build /var/www/html .
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN mkdir -p /var/www/html/storage/app/public \
             /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/testing \
             /var/www/html/storage/framework/views \
             /var/www/html/storage/logs \
             /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
