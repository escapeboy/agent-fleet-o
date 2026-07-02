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

exec "$@"
