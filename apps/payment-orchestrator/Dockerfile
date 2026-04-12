FROM php:8.4-fpm-alpine AS base

# linux-headers is required to compile ext-sockets on Alpine.
# ext-sockets is required by spiral/roadrunner-worker (temporal/sdk dependency).
# ext-grpc is NOT installed here: the Temporal worker uses RoadRunner's pipe relay,
# so PHP never calls gRPC directly. When WorkflowClientInterface is used from the
# HTTP layer (TASK-061+), add `pecl install grpc` to this stage at that point.
RUN apk add --no-cache \
        git \
        curl \
        wget \
        zip \
        unzip \
        libpq-dev \
        linux-headers \
    && docker-php-ext-install pdo pdo_pgsql opcache pcntl sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .

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

# ── worker ────────────────────────────────────────────────────────────────────
# Copies the production PHP build and adds the RoadRunner binary.
# RoadRunner handles the gRPC connection to Temporal; PHP talks to RR via pipes.
FROM base AS worker

ARG RR_VERSION=2025.1.12

COPY --from=build /var/www/html .

RUN wget -q "https://github.com/roadrunner-server/roadrunner/releases/download/v${RR_VERSION}/roadrunner-${RR_VERSION}-linux-amd64.tar.gz" \
         -O /tmp/rr.tar.gz \
    && tar xzf /tmp/rr.tar.gz -C /tmp \
    && find /tmp -name "rr" -type f -exec install -m 0755 {} /usr/local/bin/rr \; \
    && rm -rf /tmp/rr.tar.gz /tmp/roadrunner-* \
    && mkdir -p /var/www/html/storage/app/public \
               /var/www/html/storage/framework/cache/data \
               /var/www/html/storage/framework/sessions \
               /var/www/html/storage/framework/testing \
               /var/www/html/storage/framework/views \
               /var/www/html/storage/logs \
               /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

CMD ["/usr/local/bin/rr", "serve", "-c", ".rr.yaml"]