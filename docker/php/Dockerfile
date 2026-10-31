# Imagen de Laravel. Etapas:
#   base        PHP 8.4 + extensiones + Composer (común a todo)
#   dev         la que usa docker-compose.yml: el código entra por un volumen
#               (bind mount), así que no se copia nada más
#   vendor      dependencias de PHP sin las de desarrollo
#   assets      panel compilado con Vite (npm run build)
#   production  app/horizon/scheduler/ai-results en producción: el código,
#               vendor y el panel dentro de la imagen (Fase 16)
#   web         nginx con los ficheros públicos (incluido el panel compilado)
#
# Construir: docker build -f docker/php/Dockerfile --target production .

FROM php:8.4-fpm-alpine AS base

# Dependencias del sistema
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

# Extensiones de PHP que necesitan Laravel, PostgreSQL y Redis
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    bcmath \
    mbstring \
    zip \
    intl \
    opcache \
    pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM base AS dev

USER www-data
EXPOSE 9000
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY tsconfig.json vite.config.ts ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------------------------------------------------------------------------
FROM base AS production

# Versiones sin compiladores ni cabeceras: no hacen falta para ejecutar.
RUN apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/pear

COPY docker/php/opcache-production.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/entrypoint-production.sh /usr/local/bin/app-entrypoint

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Autoload optimizado y paquetes descubiertos SOLO con las dependencias de
# producción (sin Pest, Collision...).
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}
LABEL org.opencontainers.image.title="ai-mail-automation-app" \
      org.opencontainers.image.version="${APP_VERSION}"

USER www-data
EXPOSE 9000
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build

ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="ai-mail-automation-web" \
      org.opencontainers.image.version="${APP_VERSION}"
