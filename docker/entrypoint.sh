#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY. Generate one with: docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show}"
: "${MCPANEL_TOKEN:?Set MCPANEL_TOKEN to a random string of at least 32 characters}"

cd /app

# Artisan runs as the web user, so the files it writes stay writable for PHP-FPM.
as_web() {
    runuser -u www-data -- "$@"
}

# The database container may still be starting.
attempt=0
until as_web php artisan migrate --force; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "The database did not become reachable." >&2
        exit 1
    fi
    sleep 2
done

as_web php artisan panel:setup
as_web php artisan optimize

exec supervisord --nodaemon --configuration /etc/supervisor/supervisord.conf
