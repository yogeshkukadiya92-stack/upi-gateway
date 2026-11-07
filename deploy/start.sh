#!/bin/sh
set -eu
# Coolify injects the persistent application key and database configuration.
# The installer expects a writable .env even when configuration is injected.
: "${APP_KEY:?APP_KEY must be configured}"
if [ ! -f /app/.env ]; then
    umask 077
    printf 'APP_KEY=%s\n' "$APP_KEY" > /app/.env
fi
exec /start-container.sh
