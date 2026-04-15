FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    build-essential \
    pkg-config \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo_mysql mbstring gd zip bcmath pcntl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Node.js for frontend asset building
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y nodejs \
 && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-dev --no-interaction --no-progress

# Build frontend assets
RUN npm ci && npx vite build && rm -rf node_modules

# Ensure storage and cache directories are writable
RUN chmod -R 775 storage bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

# On container start: install PHP deps if missing, rebuild Vite assets
# (the repo is bind-mounted over /var/www/html by Komodo, so image-built
# assets are masked — rebuild at runtime against the current source).
CMD set -e; \
    [ -f vendor/autoload.php ] || composer install --optimize-autoloader --no-dev --no-interaction --no-progress; \
    npm ci --no-audit --no-fund && npm run build; \
    chown -R www-data:www-data storage bootstrap/cache public/build; \
    exec php-fpm
