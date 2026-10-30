# ==============================================================================
# NuraHub Auth & User Profile System - Production Dockerfile
# Optimized for Render Web Services & Standard Cloud Containers
# ==============================================================================

FROM php:8.3-apache

# Set non-interactive debian frontend
ENV DEBIAN_FRONTEND=noninteractive

# Install essential system libraries and build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libssl-dev \
    pkg-config \
    default-mysql-client \
    ca-certificates \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql zip \
    && pecl install mongodb-1.20.1 redis \
    && docker-php-ext-enable mongodb redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable required Apache modules
RUN a2enmod rewrite headers

# Allow .htaccess directives in /var/www/html
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Use official PHP production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Configure production-grade PHP settings
RUN { \
    echo 'expose_php = Off'; \
    echo 'display_errors = Off'; \
    echo 'display_startup_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /dev/stderr'; \
    echo 'error_reporting = E_ALL'; \
    echo 'memory_limit = 256M'; \
    echo 'upload_max_filesize = 10M'; \
    echo 'post_max_size = 12M'; \
    echo 'session.use_strict_mode = 1'; \
    echo 'session.cookie_httponly = 1'; \
    echo 'session.cookie_samesite = "Lax"'; \
} > "$PHP_INI_DIR/conf.d/production.ini"

# Install Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy Composer manifests first to leverage Docker layer caching
COPY composer.json composer.lock* ./

# Install production dependencies reproducibly
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copy application source files
COPY . .

# Set up runtime entrypoint script for dynamic port binding ($PORT)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Ensure proper permissions for Apache web server user
RUN chown -R www-data:www-data /var/www/html

# Expose Render standard default web port
EXPOSE 10000

# Set entrypoint to configure dynamic $PORT and start Apache
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
