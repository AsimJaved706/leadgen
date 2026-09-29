FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev libonig-dev && docker-php-ext-install pdo_mysql pdo_sqlite zip mbstring pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
