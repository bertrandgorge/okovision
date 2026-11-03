# Image de développement Okovision 2 : PHP 8.5 + Apache (équivalent hébergement O2Switch)
FROM php:8.5-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev mariadb-client \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli intl zip gd bcmath exif pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Composer + Node (build des assets en local uniquement, pas sur O2Switch)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/okovision.conf \
    && printf 'date.timezone = Europe/Paris\nmemory_limit = 1024M\nupload_max_filesize = 64M\npost_max_size = 64M\nmax_execution_time = 300\n' > /usr/local/etc/php/conf.d/okovision.ini

# Apache tourne avec l'UID/GID de l'utilisateur hôte (écriture dans storage/)
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "$GID" www-data && usermod -o -u "$UID" www-data \
    && mkdir -p /var/www/.npm /var/www/.composer && chown -R www-data:www-data /var/www

WORKDIR /var/www/html
