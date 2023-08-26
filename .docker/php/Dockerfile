# ----------------------
# The FPM base container
# ----------------------
FROM php:8.1-fpm-alpine AS dev

# Install build dependencies
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    postgresql-dev

# Cleanup apk cache and temp files
RUN rm -rf /var/cache/apk/* /tmp/*

# Install php extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql

# ----------------------
# Composer install step
# ----------------------

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ----------------------
# The FPM production container
# ----------------------
FROM dev
