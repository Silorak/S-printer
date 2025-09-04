FROM php:8.2-fpm-alpine

# Install dependencies for SNMP
RUN apk add --no-cache net-snmp-tools && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS net-snmp-dev && \
    docker-php-ext-install snmp && \
    apk del .build-deps

# Set PHP configurations
RUN echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/timezone.ini && \
    echo "max_execution_time = 30" > /usr/local/etc/php/conf.d/max_execution_time.ini

# Create directory for cache and set permissions
RUN mkdir -p /var/www/html/cache && \
    chown -R www-data:www-data /var/www/html

# Set working directory
WORKDIR /var/www/html

# Copy the PHP source files
COPY src/ /var/www/html/

# Expose port 9000 for PHP-FPM
EXPOSE 9000
