# syntax=docker/dockerfile:1.7

# Stage 1: build frontend assets with Vite
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
RUN npx vite build

# Stage 2: PHP-FPM application image
FROM php:8.4-fpm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libxml2-dev libzip-dev libicu-dev \
        zip build-essential pkg-config \
        python3 python3-venv \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring gd zip bcmath pcntl intl opcache \
    && rm -rf /var/lib/apt/lists/*

# pyHanko's CLI ships as the separate "pyhanko-cli" package (pulls in the
# "pyhanko" library itself as a dependency) - plain "pip install pyhanko"
# only installs the library and has no `pyhanko` executable at all. Pillow is
# needed separately - pyHanko's stamp-style "background" config only imports
# PIL lazily when an image background is actually used, so its absence isn't
# caught until a real signing attempt fails with ModuleNotFoundError. Placed
# before the composer/app-source layers below since it's pure OS tooling with
# no dependency on either, so it stays cached across app-code-only rebuilds.
RUN python3 -m venv /opt/pyhanko-venv \
    && /opt/pyhanko-venv/bin/pip install --no-cache-dir pyhanko-cli pillow

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
ENV COMPOSER_MEMORY_LIMIT=-1
# --no-scripts: app source not copied yet, artisan would fail
RUN composer install --no-scripts --no-interaction --no-progress --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build

# Temporary .env lets artisan bootstrap for package:discover during dump-autoload
RUN cp .env.example .env \
    && composer dump-autoload --optimize \
    && rm -f .env \
    && mkdir -p storage/app storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && cp -r storage/app/templates /opt/app-templates 2>/dev/null || true

COPY docker/php-upload.ini /usr/local/etc/php/conf.d/99-upload.ini
COPY docker/php-opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf
COPY docker/app-entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

EXPOSE 9000
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# Stage 3: nginx image with public/ baked in
FROM nginx:stable-alpine AS nginx
COPY --from=assets /app/public /var/www/html/public
COPY nginx.conf /etc/nginx/conf.d/default.conf
