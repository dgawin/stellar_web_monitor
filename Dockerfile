FROM php:8.3-apache

# pdo_sqlite und curl sind im offiziellen Image bereits enthalten
RUN a2enmod headers \
 && sed -ri 's!/var/www/html!/app/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && printf 'ServerTokens Prod\nServerSignature Off\n<Directory /app/public>\n  AllowOverride None\n  Require all granted\n</Directory>\nHeader always set X-Content-Type-Options nosniff\nHeader always set X-Frame-Options SAMEORIGIN\n' > /etc/apache2/conf-enabled/stellar.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Basic-Auth-Header an PHP durchreichen (für WEB_USER / DEBUG_PASSWORD)
RUN echo 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' >> /etc/apache2/conf-enabled/stellar.conf

COPY app/ /app/
RUN mkdir -p /data && chown -R www-data:www-data /data

ENV DB_PATH=/data/monitor.sqlite
WORKDIR /app
EXPOSE 80
