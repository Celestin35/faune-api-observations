FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libpq-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload --optimize \
    && chmod +x artisan \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
