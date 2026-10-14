#!/bin/sh
set -u

APP_DIR="/home/u782678274/domains/leads.diligenttechnologies.co/leadspace_app/backend"
cd "$APP_DIR" || exit 1

while true; do
    /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
    sleep 60
done
