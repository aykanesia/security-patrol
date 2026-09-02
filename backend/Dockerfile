# ============================================================
# Backend Security Patrol — Laravel 12 (PHP 8.3-FPM)
# Build context: folder backend/
# ============================================================
FROM php:8.3-fpm

# --- dependensi sistem + ekstensi PHP yang dibutuhkan Laravel ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        unzip \
        git \
        curl \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        intl \
        zip \
        bcmath \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --- Composer (binary dari image resmi composer) ---
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# --- install dependency (cache layer: composer.json/lock dulu) ---
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader

# --- salin source (dockerignore mengecualikan vendor/.env dll) ---
COPY . .

# --- finalize autoload + package discovery (sanctum dkk) ---
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi

# --- tuning PHP + entrypoint ---
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-securitypatrol.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/app/public storage/framework/cache/data \
                storage/framework/sessions storage/framework/testing \
                storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
