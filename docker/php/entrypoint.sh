#!/bin/sh
set -e

# Logs are written by several processes that may run as different users
# (php-fpm as www-data, artisan/scheduler as root). Make storage/logs setgid so
# new daily files inherit the www-data group, and set umask 0002 so they are
# group-writable — every writer can then append without Monolog force-chmod'ing
# the file (which throws "Operation not permitted" when it doesn't own it; see
# the 'daily' channel in config/logging.php). Best-effort; needs root at entry.
umask 0002
chmod g+s /var/www/storage/logs 2>/dev/null || true

# Sync vendor directory (named volume may be empty or stale)
if [ ! -f /var/www/vendor/autoload.php ]; then
    echo "[entrypoint] Installing Composer dependencies..."
    php -d disable_functions="" /usr/bin/composer install --no-interaction --optimize-autoloader
fi

# Wait for Redis to accept TCP connections before booting PHP.
# docker-compose's `depends_on: redis: condition: service_healthy` already
# gates the initial `docker compose up`, but it is NOT re-evaluated when a
# single container is restarted independently (e.g. `docker restart app`,
# or Redis being recreated while this container keeps running) — that gap
# is what surfaces as "RedisException: Connection refused". Prefer the
# container's real REDIS_HOST env (set explicitly in docker-compose.yml),
# falling back to the .env file (whose default is host-machine-oriented,
# 127.0.0.1, for the non-Docker `composer dev` workflow) and then 127.0.0.1.
# Uses fsockopen via PHP (always present in this image) instead of `nc`,
# which alpine's busybox does not guarantee.
env_file=/var/www/.env
redis_host="${REDIS_HOST:-$( [ -f "$env_file" ] && grep -m1 '^REDIS_HOST=' "$env_file" | cut -d '=' -f2- )}"
redis_port="${REDIS_PORT:-$( [ -f "$env_file" ] && grep -m1 '^REDIS_PORT=' "$env_file" | cut -d '=' -f2- )}"
redis_host="${redis_host:-127.0.0.1}"
redis_port="${redis_port:-6379}"

attempt=0
max_attempts=30
until php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $errno, $errstr, 1) ? 0 : 1);' "$redis_host" "$redis_port"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "[entrypoint] Redis at ${redis_host}:${redis_port} still unreachable after ${max_attempts}s, continuing anyway" >&2
        break
    fi
    echo "[entrypoint] Waiting for Redis at ${redis_host}:${redis_port}... (${attempt}/${max_attempts})"
    sleep 1
done

exec "$@"
