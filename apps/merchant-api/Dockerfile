FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        git \
        curl \
        zip \
        unzip \
        libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan event:cache

FROM base AS production

COPY --from=build /var/www/html .

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
