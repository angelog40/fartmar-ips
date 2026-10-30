FROM composer:2.10 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM php:8.3-fpm-alpine AS runtime
RUN apk add --no-cache nginx libpq-dev curl \
    && docker-php-ext-install pdo pdo_pgsql pcntl

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && sed -i 's/^user /#user /' /etc/nginx/nginx.conf \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html /run/nginx /var/lib/nginx /var/log/nginx

USER www-data
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]