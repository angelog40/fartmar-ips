#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

php artisan key:generate --force --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

php-fpm -D
nginx -g "daemon off;"