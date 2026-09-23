FROM dunglas/frankenphp:1-php8.4

# Install required system extensions for Symfony 7
RUN install-php-extensions \
    pdo_pgsql \
    pdo_mysql \
    intl \
    zip \
    opcache \
    xml \
    apcu \
    xml \
    amqp

WORKDIR /app

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /app

# For PoC, do NOT skip dev dependencies.
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install  --optimize-autoloader

# Default entrypoint serves HTTP on port 8080
ENV PORT=8080
EXPOSE 8080
