FROM php:8.4-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install system dependencies for exif
RUN apk add --no-cache libexif-dev

# Install system dependencies for GD (png/jpeg/freetype) — needed by
# UploadedFile::fake()->image() used in bug-report + widget tests.
RUN apk add --no-cache libpng-dev libjpeg-turbo-dev freetype-dev

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    pcntl \
    opcache \
    bcmath \
    exif \
    gd

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Configure OPcache
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq=2" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size=128M" >> /usr/local/etc/php/conf.d/opcache.ini

# Security hardening (disable dangerous functions, hide PHP version, block remote includes)
COPY docker/php/conf.d/security.ini /usr/local/etc/php/conf.d/security.ini

# PHP config
RUN echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/app.ini \
    && echo "upload_max_filesize=64M" >> /usr/local/etc/php/conf.d/app.ini \
    && echo "post_max_size=64M" >> /usr/local/etc/php/conf.d/app.ini \
    && echo "max_execution_time=120" >> /usr/local/etc/php/conf.d/app.ini

# Install Boruna MCP server binary (optional — skip if BORUNA_VERSION is empty)
# Boruna provides a deterministic capability-safe .ax script executor via MCP stdio.
# Set BORUNA_VERSION build arg to pin a specific release (e.g. --build-arg BORUNA_VERSION=0.3.0).
ARG BORUNA_VERSION=""
RUN if [ -n "$BORUNA_VERSION" ]; then \
        apk add --no-cache curl tar && \
        ARCH=$(uname -m | sed 's/x86_64/x86_64/;s/aarch64/aarch64/') && \
        curl -fsSL "https://github.com/escapeboy/boruna/releases/download/v${BORUNA_VERSION}/boruna-${ARCH}-unknown-linux-musl.tar.gz" \
            -o /tmp/boruna.tar.gz && \
        tar -xzf /tmp/boruna.tar.gz -C /tmp && \
        mv /tmp/boruna /usr/local/bin/boruna && \
        chmod +x /usr/local/bin/boruna && \
        rm -f /tmp/boruna.tar.gz; \
    fi

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Claude Code (npm package) — used by the super-admin-gated
# VPS provider. The binary is harmless if unused: the feature is gated
# behind CLAUDE_CODE_OAUTH_TOKEN + user.is_super_admin + team.claude_code_vps_allowed.
# Set CLAUDE_CODE_VERSION build arg to pin a release (e.g. --build-arg CLAUDE_CODE_VERSION=1.0.0).
ARG CLAUDE_CODE_VERSION="latest"
RUN apk add --no-cache nodejs npm \
    && npm install -g @anthropic-ai/claude-code@${CLAUDE_CODE_VERSION} \
    && ln -sf "$(npm prefix -g)/bin/claude" /usr/local/bin/claude \
    && claude --version

WORKDIR /var/www

# ---------- Development stage ----------
FROM base AS development

# Node.js + npm already installed in base stage (see Claude Code install).
# Vite can reuse the same binaries.

COPY . .

RUN php -d disable_functions="" /usr/bin/composer install --no-interaction --optimize-autoloader

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---------- Production stage ----------
FROM base AS production

COPY . .

RUN php -d disable_functions="" /usr/bin/composer install --no-dev --no-interaction --optimize-autoloader \
    && php -d disable_functions="" artisan config:cache \
    && php -d disable_functions="" artisan route:cache \
    && php -d disable_functions="" artisan view:cache

# Set proper permissions
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
