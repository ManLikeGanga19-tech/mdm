# Laravel app image: web (FrankenPHP), Horizon worker and scheduler all run from it (docker-compose.yml).
FROM dunglas/frankenphp:1.13.1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql redis intl zip bcmath pcntl gd exif opcache \
    && apt-get update && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Development: the source is bind-mounted at /app. Production builds copy it in (deploy/, Phase 6 work).
WORKDIR /app
ENV SERVER_NAME=":80"
