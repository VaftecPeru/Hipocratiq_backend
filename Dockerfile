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

COPY Caddyfile /etc/caddy/Caddyfile

RUN composer dump-autoload --optimize

RUN mkdir -p storage/framework/{sessions,views,cache,testing} storage/logs bootstrap/cache \
    && chmod -R a+rw storage bootstrap/cache

ENV SERVER_NAME=:8000

EXPOSE 8000

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
