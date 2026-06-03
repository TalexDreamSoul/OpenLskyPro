# syntax=docker/dockerfile:1

FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY webpack.mix.js tailwind.config.js ./
RUN npm run production

FROM php:8.1-cli-bookworm AS vendor
WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libssl-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" ftp zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts \
    --ignore-platform-req=ext-imagick
COPY . .
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev --no-scripts

FROM php:8.1-fpm-bookworm AS app
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        git \
        unzip \
        libzip-dev \
        libssl-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libmagickwand-dev \
        libxml2-dev \
        libonig-dev \
        libpq-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        ctype \
        dom \
        fileinfo \
        ftp \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-openlskypro.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-openlskypro.conf
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/openlskypro.conf
COPY docker/entrypoint.sh /usr/local/bin/openlskypro-entrypoint

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public ./public

RUN chmod +x /usr/local/bin/openlskypro-entrypoint \
    && mkdir -p storage/app/uploads storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public

EXPOSE 80
ENTRYPOINT ["openlskypro-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/openlskypro.conf"]
