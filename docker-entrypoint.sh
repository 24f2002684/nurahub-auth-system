#!/bin/sh
set -e

# Default port to 10000 if not specified (Render default web service port)
PORT="${PORT:-10000}"

# Dynamically adjust Apache listening port in ports.conf and default VirtualHost
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# If Aiven CA certificate content is provided via environment variable, save it to /etc/ssl/certs/aiven-ca.pem
if [ -n "$MYSQL_SSL_CA_CONTENT" ]; then
    mkdir -p /etc/ssl/certs
    echo "$MYSQL_SSL_CA_CONTENT" > /etc/ssl/certs/aiven-ca.pem
    chmod 644 /etc/ssl/certs/aiven-ca.pem
fi

# Execute the container's primary process (apache2-foreground)
exec "$@"
