#!/bin/sh
set -e
cd /var/www/html

php artisan migrate --force
php artisan config:cache
php artisan view:cache
# The caches above are written as root; Apache and the workers run as www-data.
chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /var/www/html/docker/supervisord.conf
