FROM php:8.3.9-fpm

# Install system packages and PHP extensions in a single layer to reduce image size
RUN apt-get update && apt-get install -y --no-install-recommends \
        bash \
        nano \
        git \
        unzip \
        locales \
        curl \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libfreetype6-dev \
        libonig-dev \
        libzip-dev \
        libicu-dev \
        libpq-dev \
        g++ \
        make \
        autoconf \
        supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd mbstring pdo pdo_mysql pdo_pgsql pgsql zip intl mysqli opcache exif pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer (pinned to major version for reproducible builds)
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Build-time variable for project path inside the container
ARG DOCKER_BASE_PATH=/var/www

# Runtime environment variable — accessible in running PHP scripts
ENV DOCKER_BASE_PATH=${DOCKER_BASE_PATH}

# Set working directory for all subsequent commands
WORKDIR ${DOCKER_BASE_PATH}

# Copy project files into the image (respects .dockerignore)
COPY . ${DOCKER_BASE_PATH}

# Set correct ownership and permissions for Laravel writable directories
RUN chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# Load custom PHP configuration
COPY .docker/php/laravel.ini /usr/local/etc/php/conf.d/

# Create directories required by Supervisor
RUN mkdir -p /var/log/supervisor /var/run

# Copy Supervisor process manager configuration
COPY .docker/supervisord/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Start all processes via Supervisor (php-fpm + horizon)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
