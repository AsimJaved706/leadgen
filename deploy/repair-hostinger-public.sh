#!/bin/sh
set -eu

DOMAIN_ROOT="/home/u782678274/domains/leads.diligenttechnologies.co"
APP_ROOT="$DOMAIN_ROOT/leadspace_app"
BUILD_ROOT="$DOMAIN_ROOT/hbuilds/current/nodejs"
PUBLIC_ROOT="$DOMAIN_ROOT/public_html"
BACKEND_PUBLIC="$APP_ROOT/backend/public"
LOCK_FILE="$DOMAIN_ROOT/.repair-public.lock"

(
    flock -n 9 || exit 0

    [ -f "$BUILD_ROOT/index.html" ] || exit 0
    mkdir -p "$PUBLIC_ROOT/assets"

    if [ ! -f "$PUBLIC_ROOT/index.html" ] || ! cmp -s "$BUILD_ROOT/index.html" "$PUBLIC_ROOT/index.html"; then
        cp "$BUILD_ROOT/index.html" "$PUBLIC_ROOT/index.html"
    fi

    cp -a "$BUILD_ROOT/assets/." "$PUBLIC_ROOT/assets/"
    cp "$APP_ROOT/public/index.php" "$PUBLIC_ROOT/index.php"
    cp "$APP_ROOT/public/.htaccess" "$PUBLIC_ROOT/.htaccess"

    mkdir -p "$BACKEND_PUBLIC/assets"
    cp "$BUILD_ROOT/index.html" "$BACKEND_PUBLIC/index.html"
    cp -a "$BUILD_ROOT/assets/." "$BACKEND_PUBLIC/assets/"

    chmod 755 "$PUBLIC_ROOT" "$PUBLIC_ROOT/assets"
    find "$PUBLIC_ROOT/assets" -type d -exec chmod 755 {} +
    find "$PUBLIC_ROOT/assets" -type f -exec chmod 644 {} +
    chmod 644 "$PUBLIC_ROOT/index.html" "$PUBLIC_ROOT/index.php" "$PUBLIC_ROOT/.htaccess"
    chmod 755 "$BACKEND_PUBLIC" "$BACKEND_PUBLIC/assets"
    find "$BACKEND_PUBLIC/assets" -type d -exec chmod 755 {} +
    find "$BACKEND_PUBLIC/assets" -type f -exec chmod 644 {} +
    chmod 644 "$BACKEND_PUBLIC/index.html"
) 9>"$LOCK_FILE"

if [ "${1:-}" = "--watch" ]; then
    while sleep 15; do
        "$0"
    done
fi
