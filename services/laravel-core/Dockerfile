FROM php:8.4-fpm-alpine AS base
RUN apk add --no-cache bash git curl unzip icu-dev libpq-dev libzip-dev oniguruma-dev postgresql-client $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pgsql bcmath intl opcache pcntl zip \
    && pecl install redis && docker-php-ext-enable redis

FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM base
WORKDIR /var/www/html
COPY --from=build /var/www/html .
RUN mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Do NOT cache config — we need env() to work at runtime, and the
# alternatives (caching with runtime env, or building the cache from
# the wrong env) break the service resolution chain. `serve` is the
# v1 process model; a real deployment would use `php-fpm` behind
# nginx + a worker, but that's P4 scope.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]