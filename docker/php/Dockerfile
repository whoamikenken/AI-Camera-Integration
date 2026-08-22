FROM php:8.3-fpm-bookworm

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

# Install system dependencies & libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    zip \
    unzip \
    supervisor \
    procps \
    netcat-traditional \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Docker PHP extension installer helper
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        bcmath \
        pcntl \
        posix \
        sockets \
        gd \
        zip \
        intl \
        opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy custom PHP configuration
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Set working directory
WORKDIR /var/www

# Expose PHP-FPM default port
EXPOSE 9000

CMD ["php-fpm"]
