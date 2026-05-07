# syntax=docker/dockerfile:1.7
#
# FleetQ Community Edition — Glama / standalone MCP server image.
#
# Boots the *compact* 33-tool MCP server on stdio with a self-contained
# SQLite database. No Postgres, no Redis, no external services required.
#
# Build:   docker build -t fleetq/mcp .
# Run:     docker run --rm -i fleetq/mcp
#
# Targets the Glama auto-test pipeline: a single image whose CMD speaks
# MCP JSON-RPC on stdin/stdout.

# ─────────────────────────────────────────────────────────────
# Stage 1 — composer install + autoload optimization
# ─────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS builder

RUN apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        sqlite-dev \
        oniguruma-dev \
        linux-headers \
        $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        zip \
        intl \
        bcmath \
        gd \
        pcntl \
        opcache \
        exif \
 && pecl install redis \
 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Cache composer install separately from app code.
# `packages/` contains local path-repo packages referenced from composer.json, so
# it must be copied BEFORE `composer install` resolves the dependency graph.
COPY composer.json composer.lock ./
COPY packages/ ./packages/
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

# App source
COPY . .

# Default env so the post-autoload-dump artisan calls (package:discover) succeed.
# These values are also kept in the runtime stage.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/database/database.sqlite \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    BROADCAST_DRIVER=log \
    FILESYSTEM_DISK=local \
    BCRYPT_ROUNDS=4 \
    REDIS_CLIENT=phpredis \
    REDIS_HOST=127.0.0.1

# Prepare runtime dirs + .env BEFORE any artisan call. `composer dump-autoload`
# triggers `package:discover --ansi` which needs (a) vendor/autoload.php,
# (b) writable bootstrap/cache, and (c) a populated APP_KEY in .env. We set a
# placeholder key first, then dump-autoload, then regenerate a real key.
RUN cp .env.example .env \
 && touch /app/database/database.sqlite \
 && mkdir -p /app/bootstrap/cache \
              /app/storage/framework/cache/data \
              /app/storage/framework/sessions \
              /app/storage/framework/views \
              /app/storage/logs \
 && chmod -R 777 /app/storage /app/bootstrap/cache /app/database \
 && sed -i 's|^APP_KEY=.*|APP_KEY=base64:Z2xhbWEtZG9ja2VyLWJ1aWxkLXBsYWNlaG9sZGVy|' .env \
 && composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && php artisan key:generate --force \
 && php artisan config:clear \
 && php artisan migrate --force --no-interaction \
 && php artisan db:seed --class=Database\\Seeders\\DemoTeamSeeder --force --no-interaction

# ─────────────────────────────────────────────────────────────
# Stage 2 — slim runtime
# ─────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS runtime

RUN apk add --no-cache \
        libzip \
        icu-libs \
        libpng \
        libjpeg-turbo \
        freetype \
        sqlite-libs \
        oniguruma \
 && apk add --no-cache --virtual .build-deps \
        libzip-dev icu-dev libpng-dev libjpeg-turbo-dev freetype-dev sqlite-dev oniguruma-dev \
        linux-headers $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        zip \
        intl \
        bcmath \
        gd \
        pcntl \
        opcache \
 && apk del .build-deps \
 && rm -rf /tmp/* /var/cache/apk/*

WORKDIR /app

COPY --from=builder /app /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/database/database.sqlite \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    BROADCAST_DRIVER=log \
    FILESYSTEM_DISK=local \
    BCRYPT_ROUNDS=4 \
    REDIS_CLIENT=phpredis \
    REDIS_HOST=127.0.0.1 \
    PHP_CLI_SERVER_WORKERS=1

# stdio MCP server — Glama connects to this process.
CMD ["php", "artisan", "mcp:start", "compact"]
