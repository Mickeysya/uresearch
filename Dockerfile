# Production image for Render's free tier: Apache + queue worker + scheduler
# in one container (free tier has no background workers). Local dev still
# uses docker-compose.yml + `php artisan serve`; this file is deploy-only.
FROM php:8.3-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql pdo_mysql gd zip intl bcmath opcache \
 && apt-get update && apt-get install -y --no-install-recommends supervisor \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Dependencies first so code edits don't bust the composer layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN echo 'Listen ${PORT}' > /etc/apache2/ports.conf

# Render injects PORT; 10000 is its default.
ENV PORT=10000
EXPOSE 10000

CMD ["sh", "/var/www/html/docker/start.sh"]
