#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY, see .env.compose.example}"

cd /app

# The queue worker and the scheduler run from this same image with RUN_MIGRATIONS=false:
# only the web container prepares the database.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    : "${SPAWNPOINT_TOKEN:?Set SPAWNPOINT_TOKEN to a random string of at least 32 characters}"

    # The database container may still be starting.
    attempt=0
    until php artisan migrate --force; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "The database did not become reachable." >&2
            exit 1
        fi
        sleep 2
    done

    php artisan panel:setup
fi

# Cache config, routes and views against the environment of this container.
php artisan optimize

exec "$@"
