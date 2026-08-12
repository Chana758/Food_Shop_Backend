FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git curl libpq-dev libzip-dev zip unzip \
    libonig-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_pgsql zip mbstring xml bcmath

WORKDIR /var/www
COPY . .

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --optimize-autoloader --no-dev

EXPOSE 8000
CMD php artisan config:cache && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000
