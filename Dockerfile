FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions \
    pcntl \
    pdo_pgsql \
    redis \
    zip \
    bcmath \
    intl \
    gd

WORKDIR /app

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache

RUN mkdir -p storage/framework/{sessions,views,cache,testing} storage/logs bootstrap/cache \
    && chmod -R a+rw storage bootstrap/cache

ENV SERVER_NAME=:8000

EXPOSE 8000

CMD php artisan config:cache && php artisan migrate --force && frankenphp run --config /etc/caddy/Caddyfile
