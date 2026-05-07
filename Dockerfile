# FleetQ Community Edition — Glama / standalone MCP server image.
#
# Boots the *compact* 33-tool MCP server on stdio with a self-contained
# SQLite database. No Postgres, no Redis, no external services required.
#
# Build:   docker build -t fleetq/mcp .
# Run:     docker run --rm -i fleetq/mcp
#
# Targets the Glama auto-test pipeline: a single image whose CMD speaks
# MCP JSON-RPC on stdin/stdout. Optimized for fast, deterministic builds
# inside resource-constrained CI runners.

# ─────────────────────────────────────────────────────────────
# Stage 1 — composer install + Laravel pre-bake (SQLite)
# ─────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS builder

# Build-time toolchain + minimum extensions for Laravel + MCP boot.
# We deliberately omit gd / exif / redis-ext / pcntl: the compact MCP
# server's tools/list does not exercise image processing, EXIF, queues,
# or Redis — the runtime uses array cache and sync queues.
RUN apk add --no-cache \
        git unzip \
        libzip-dev icu-dev sqlite-dev oniguruma-dev \
        $PHPIZE_DEPS \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite zip intl bcmath opcache \
 && apk del $PHPIZE_DEPS \
 && rm -rf /var/cache/apk/* /tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Composer install — `packages/` is a local path-repo source so it MUST
# be present before the dependency graph resolves.
# `ext-exif` (spatie/laravel-medialibrary) and `ext-pcntl` (Horizon) are
# declared by deps but unreachable from the compact MCP boot path —
# tools/list never opens an image or forks a queue worker.
COPY composer.json composer.lock ./
COPY packages/ ./packages/
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist \
        --ignore-platform-req=ext-exif \
        --ignore-platform-req=ext-pcntl \
 && rm -rf ~/.composer

# App source
COPY . .

# Default env baked into the image. The MCP stdio server reads these on
# every invocation; the runtime stage repeats them verbatim.
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
    MAIL_MAILER=log

# `composer dump-autoload` triggers `package:discover` which needs (a)
# vendor/autoload.php, (b) writable bootstrap/cache, and (c) APP_KEY in
# .env. Set a placeholder key first; regenerate before migrate.
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
       --ignore-platform-req=ext-exif --ignore-platform-req=ext-pcntl \
 && php artisan key:generate --force \
 && php artisan migrate --force --no-interaction \
 && php artisan db:seed --class=Database\\Seeders\\DemoTeamSeeder --force --no-interaction \
 && rm -rf /app/storage/logs/*.log

# ─────────────────────────────────────────────────────────────
# Stage 2 — slim runtime
# ─────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS runtime

RUN apk add --no-cache \
        libzip icu-libs sqlite-libs oniguruma \
 && apk add --no-cache --virtual .build-deps \
        libzip-dev icu-dev sqlite-dev oniguruma-dev $PHPIZE_DEPS \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite zip intl bcmath opcache \
 && apk del .build-deps \
 && rm -rf /var/cache/apk/* /tmp/*

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
    MAIL_MAILER=log

LABEL org.opencontainers.image.title="FleetQ MCP Server" \
      org.opencontainers.image.description="33-tool MCP server (stdio) for FleetQ AI Agent Mission Control" \
      org.opencontainers.image.source="https://github.com/escapeboy/agent-fleet-o" \
      org.opencontainers.image.licenses="AGPL-3.0"

# stdio MCP server — Glama / Claude Desktop / Codex connect here.
CMD ["php", "artisan", "mcp:start", "compact"]
