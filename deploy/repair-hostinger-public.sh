#!/bin/sh
set -eu

DOMAIN_ROOT="/home/u782678274/domains/leads.diligenttechnologies.co"
APP_ROOT="$DOMAIN_ROOT/leadspace_app"
BUILD_ROOT="$DOMAIN_ROOT/hbuilds/current/nodejs"
PUBLIC_ROOT="$DOMAIN_ROOT/public_html"
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

    chmod 755 "$PUBLIC_ROOT" "$PUBLIC_ROOT/assets"
    find "$PUBLIC_ROOT/assets" -type d -exec chmod 755 {} +
    find "$PUBLIC_ROOT/assets" -type f -exec chmod 644 {} +
    chmod 644 "$PUBLIC_ROOT/index.html" "$PUBLIC_ROOT/index.php" "$PUBLIC_ROOT/.htaccess"
) 9>"$LOCK_FILE"

if [ "${1:-}" = "--watch" ]; then
    while sleep 15; do
        "$0"
    done
fi
