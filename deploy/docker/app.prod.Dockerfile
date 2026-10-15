# Production image for the Contabo VPS: code baked in, no dev dependencies. Web (FrankenPHP), Horizon worker and
# scheduler all run from it (deploy/docker-compose.prod.yml). Build context: the repo root.
FROM dunglas/frankenphp:1.13.1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql redis intl zip bcmath pcntl gd exif opcache \
    && apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first so code-only changes reuse this layer.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi

ENV SERVER_NAME=":80"
CMD ["frankenphp", "php-server", "--listen", ":80", "--root", "/app/public"]
