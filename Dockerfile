FROM php:8.2-apache

# Install dependencies in a single RUN command to reduce layers
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    libsnmp-dev \
    snmp && \
    docker-php-ext-install snmp pdo_mysql && \
    a2enmod rewrite headers && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Set PHP configurations
RUN echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/timezone.ini && \
    echo "max_execution_time = 30" > /usr/local/etc/php/conf.d/max_execution_time.ini && \
    printf "expose_php = Off\ndisplay_errors = Off\nlog_errors = On\n" > /usr/local/etc/php/conf.d/hardening.ini

# Create directory structure
# cache: 0755 (owner-writable) - CacheManager also drops an .htaccess in it,
# because cached JSON may contain serial numbers and must not be web-readable.
RUN mkdir -p /var/www/html/cache && \
    chown -R www-data:www-data /var/www/html && \
    chmod 755 /var/www/html/cache

# Apache configuration
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Startup: make sure the cache dir exists and is writable (bind-mount friendly),
# then hand over to the upstream php-apache entrypoint.
COPY docker-entrypoint.sh /usr/local/bin/sprinter-entrypoint.sh
RUN chmod +x /usr/local/bin/sprinter-entrypoint.sh
ENTRYPOINT ["sprinter-entrypoint.sh"]
# Explicit CMD: setting ENTRYPOINT clears the base image's inherited CMD.
CMD ["apache2-foreground"]

# Set working directory
WORKDIR /var/www/html

# Only copy files when not using volumes (for production)
# COPY src/ /var/www/html/

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s CMD curl -f http://localhost/ || exit 1