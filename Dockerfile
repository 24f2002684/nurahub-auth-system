FROM php:8.3-apache

# Install system dependencies & libraries required for extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libssl-dev \
    pkg-config \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql zip \
    && pecl install mongodb redis \
    && docker-php-ext-enable mongodb redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Install Composer from official docker image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Adjust permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
