#!/bin/sh
set -e

#
# Everything that has to be true before the app can answer a request, done once
# per container start and safe to repeat.
#
# Two containers run this image — the web app and the scheduler — and only one of
# them may prepare the database. `KITCHEN_ROLE=scheduler` is the passenger.
#

APP_DIR=/app
DB_FILE="${DB_DATABASE:-/data/database.sqlite}"
SEED_DIR="${KITCHEN_SEED_DIR:-/seed}"
ROLE="${KITCHEN_ROLE:-web}"

cd "$APP_DIR"

# --- the environment file -----------------------------------------------------
#
# Kept on the persistent volume rather than in the image, because it holds the
# APP_KEY. A new key on every rebuild would sign every phone out and make
# anything already encrypted unreadable.
if [ "$ROLE" = "web" ]; then
    if [ ! -f /data/.env ]; then
        mkdir -p /data
        cp .env.docker /data/.env
    fi
else
    # The app writes it on first boot. Compose waits for the app to be healthy
    # before starting this one, so the wait is a formality — but a formality
    # that turns a crash loop into a five-second pause if that ever changes.
    waited=0
    while [ ! -f /data/.env ] && [ "$waited" -lt 60 ]; do
        sleep 1
        waited=$((waited + 1))
    done
fi

ln -sf /data/.env "$APP_DIR/.env"

if [ "$ROLE" = "web" ] && ! grep -q '^APP_KEY=base64:' /data/.env; then
    php artisan key:generate --force --no-interaction
fi

# --- writable directories -----------------------------------------------------
#
# `storage` is a volume, so it starts empty and the framework's own
# subdirectories have to be put back. Laravel does not create these itself and
# fails obscurely without them — "failed to open stream", from a view compile,
# halfway through a request.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# --- the database -------------------------------------------------------------
if [ "$ROLE" = "web" ]; then
    #
    # First run imports the household's real database: ten thousand recipes, the
    # curated ingredient dictionary, the kitchen, the lists. Starting from an
    # empty schema would technically work and would be useless — re-importing
    # the catalogue is days of polite crawling.
    #
    # Only when there is nothing there yet. This must never overwrite a database
    # the container has been writing to, so the check is on the destination
    # rather than on the source.
    if [ ! -f "$DB_FILE" ]; then
        mkdir -p "$(dirname "$DB_FILE")"

        if [ -f "$SEED_DIR/database.sqlite" ]; then
            echo "kitchen: importing the existing database from $SEED_DIR/database.sqlite"
            cp "$SEED_DIR/database.sqlite" "$DB_FILE"

            # It runs in WAL mode, so the newest writes may still be in the
            # sidecar log rather than in the main file; copying that too is what
            # makes the import complete, and SQLite replays it on first open.
            # `-shm` is deliberately left behind: it is a memory map, it is
            # rebuilt, and a stale one is worse than none.
            if [ -f "$SEED_DIR/database.sqlite-wal" ]; then
                cp "$SEED_DIR/database.sqlite-wal" "$DB_FILE-wal"
            fi
        else
            echo "kitchen: nothing to import at $SEED_DIR/database.sqlite — starting empty"
            touch "$DB_FILE"
            KITCHEN_FRESH=1
        fi
    fi

    php artisan migrate --force --no-interaction

    # The reference data — units, the ingredient dictionary, their weights — is
    # the one thing an empty database cannot be useful without. Skipped entirely
    # over an imported one, which already has it and has had it curated since.
    if [ "${KITCHEN_FRESH:-0}" = "1" ]; then
        php artisan db:seed --force --no-interaction
    fi
else
    waited=0
    while [ ! -f "$DB_FILE" ] && [ "$waited" -lt 60 ]; do
        sleep 1
        waited=$((waited + 1))
    done
fi

# --- caches -------------------------------------------------------------------
#
# Per container, because `bootstrap/cache` is inside the image rather than on a
# shared volume. Rebuilt every start rather than baked in at build time: the
# image does not know its own APP_URL, and a config cache built without one is
# the kind of fault that only surfaces as a wrong address inside a QR code.
php artisan config:cache

if [ "$ROLE" = "web" ]; then
    php artisan route:cache
    php artisan view:cache
fi

# The base image's own entrypoint knows how to hand arguments to FrankenPHP;
# falling through to the command directly keeps this working if it ever stops
# shipping one.
if command -v docker-entrypoint >/dev/null 2>&1; then
    exec docker-entrypoint "$@"
fi

exec "$@"
