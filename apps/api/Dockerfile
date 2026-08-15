FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_pgsql pgsql zip pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
