#!/bin/sh
set -e

# Sync vendor directory (named volume may be empty or stale)
if [ ! -f /var/www/vendor/autoload.php ]; then
    echo "[entrypoint] Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

exec "$@"
