# Multi-stage build for Hiyori CLI
FROM php:8.3-cli-bookworm AS base

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libcurl4-openssl-dev \
    libssl-dev \
    libxml2-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    curl \
    mbstring \
    xml \
    zip \
    opcache

# Install MongoDB extension
RUN pecl install mongodb && docker-php-ext-enable mongodb

# Configure PHP
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini \
    && echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /app

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files first for caching
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

# Copy application code
COPY . .

# Generate autoloader
RUN composer dump-autoload --optimize --no-dev

# Make hiyori executable
RUN chmod +x hiyori

# Create non-root user
RUN useradd -m -u 1000 hiyori && chown -R hiyori:hiyori /app
USER hiyori

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php hiyori health --json || exit 1

ENTRYPOINT ["php", "hiyori"]
CMD ["list"]
