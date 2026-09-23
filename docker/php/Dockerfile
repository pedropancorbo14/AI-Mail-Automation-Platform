FROM php:8.4-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    bash \
    curl \
    git \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    oniguruma-dev \
    icu-dev \
    autoconf \
    g++ \
    make \
    linux-headers

# PHP extensions required by Laravel + Postgres + Redis
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    bcmath \
    mbstring \
    zip \
    intl \
    opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]