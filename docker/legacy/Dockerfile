FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" mysqli gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Apache tourne avec le même UID/GID que l'utilisateur hôte (écriture dans _logs, _tmp, config.json)
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "$GID" www-data && usermod -o -u "$UID" www-data

RUN { \
      echo 'AddDefaultCharset UTF-8'; \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-enabled/okovision.conf \
    && { \
      echo 'date.timezone = Europe/Paris'; \
      echo 'upload_max_filesize = 64M'; \
      echo 'post_max_size = 64M'; \
      echo 'memory_limit = 512M'; \
      echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/okovision.ini
