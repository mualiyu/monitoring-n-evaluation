# syntax=docker/dockerfile:1.7
# =============================================================================
# M&E Platform — container image
#
# Stages
#   vendor  : composer dependencies, no dev, autoloader + package manifest baked
#   assets  : Vite/Tailwind build (needs vendor/, see the @source note below)
#   app     : PHP-FPM runtime. One image, three roles: php-fpm | horizon | scheduler
#   web     : nginx serving public/ and proxying PHP to the app container
#
#   docker build --target app -t mne/app .
#   docker build --target web -t mne/web .
#
# The image is immutable: opcache does not stat files (see docker/php/php.ini),
# so a code change means a rebuild, never a restart.
# =============================================================================

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22
ARG NGINX_VERSION=1.27

# --- PHP base ----------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS php_base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# exif    — AttachDocument reads GPS/EXIF off field photos before storing them
# gd+zip  — medialibrary conversions, maatwebsite/excel, dompdf
# pcntl   — Horizon's worker supervision and graceful restarts (posix too)
# redis   — phpredis; faster than predis for the queue/cache hot path
# intl    — Carbon/number formatting for the NGN + Africa/Lagos instance defaults
RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        posix \
        redis \
        zip \
    && apk add --no-cache fcgi su-exec tzdata

WORKDIR /var/www/html

# --- Composer dependencies ----------------------------------------------------
FROM php_base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# Dependencies resolve from the lock file alone, so this layer survives every
# change that does not touch composer.json/composer.lock.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

COPY . .

# Also runs post-autoload-dump → package:discover, so the package manifest is
# baked in rather than written on first boot by a process that may not own it.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- Front-end assets ---------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN --mount=type=cache,target=/root/.npm npm ci

COPY . .

# resources/css/app.css declares `@source` over the framework's pagination views
# and the compiled-Blade cache. Both have to exist at build time or Tailwind
# silently emits a stylesheet missing those classes.
COPY --from=vendor \
    /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
    ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN mkdir -p storage/framework/views

# Needs network: the bunny() font plugin fetches Instrument Sans during build.
RUN npm run build

# --- Application runtime ------------------------------------------------------
FROM php_base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-platform.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-platform.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=vendor --chown=www-data:www-data /var/www/html/bootstrap/cache ./bootstrap/cache
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p \
        storage/app/public \
        storage/app/private \
        storage/app/documents \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/media-library/temp \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

# php-fpm | horizon | scheduler | any artisan command
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- Web server ---------------------------------------------------------------
FROM nginx:${NGINX_VERSION}-alpine AS web

COPY docker/nginx/app.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

# storage/app/public is mounted in from the shared volume at runtime; the
# private documents disk is never reachable from the document root.
RUN ln -sfn ../storage/app/public /var/www/html/public/storage

EXPOSE 80
