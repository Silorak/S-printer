#!/bin/sh
set -e

# Ensure the cache directory exists and belongs to www-data.
# With a bind-mounted docroot this is the difference between a working
# per-printer cache and re-polling every printer on every page load.
CACHE_DIR=/var/www/html/cache
mkdir -p "$CACHE_DIR" 2>/dev/null || true
chown www-data:www-data "$CACHE_DIR" 2>/dev/null || true

# Hide server version from responses (ServerTokens lives in server config)
printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/sprinter-hardening.conf 2>/dev/null || true
a2enconf sprinter-hardening >/dev/null 2>&1 || true

# Background poller (daemon): continuously round-robins the printers so the
# cache is always warm and the alert engine reacts within seconds. Output
# lands in the container log. Disable with POLLING_ENABLED=0.
if [ "${POLLING_ENABLED:-1}" = "1" ]; then
    # Run as www-data: the poller MUST share the web app's cache directory,
    # otherwise pages never see the daemon's fresh data (split-brain cache).
    (
        # small initial delay: apache+db come up first
        sleep 10
        cd /var/www/html
        su -s /bin/sh www-data -c "php /var/www/html/cron.php --daemon"
    ) &
fi

exec docker-php-entrypoint "$@"
