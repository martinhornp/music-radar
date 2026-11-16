# Local stand-in for one.com's Apache + PHP 8.4 hosting.
FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Serve the API from its own mount at /api, so rebuilding frontend/dist
# never touches it. On one.com, api/ simply sits inside the web root.
COPY api-alias.conf /etc/apache2/conf-enabled/api-alias.conf
