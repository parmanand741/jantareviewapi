FROM php:8.2-apache

# System libraries + PHP extensions CodeIgniter 4 and google/apiclient need.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        unzip \
        git \
        ca-certificates \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl mbstring zip opcache \
    && a2enmod rewrite headers \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer 2 for installing PHP dependencies at build time.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_DIR=/app
WORKDIR ${APP_DIR}

# Application source (see .dockerignore for what is excluded).
COPY . ${APP_DIR}

# Production dependencies only, optimized autoloader.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Point Apache at the CodeIgniter public/ directory (never the project root).
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Pre-create writable tree; the entrypoint fixes ownership/permissions at boot.
RUN mkdir -p ${APP_DIR}/writable/secrets \
             ${APP_DIR}/writable/session \
             ${APP_DIR}/writable/review/otp \
             ${APP_DIR}/writable/cache \
             ${APP_DIR}/writable/logs \
             ${APP_DIR}/writable/uploads \
             ${APP_DIR}/writable/debugbar

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
# Strip any Windows CRLF so bash runs cleanly, then make executable.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
