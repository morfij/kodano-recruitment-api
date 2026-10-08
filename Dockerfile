FROM php:8.4-fpm

# PHP extension installer (https://github.com/mlocati/docker-php-extension-installer)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql intl opcache zip

# Run as the host user so files created in the container (composer, cache) are not owned by root
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g "${GID}" www-data \
    && mkdir -p /var/www/app \
    && chown www-data:www-data /var/www/app

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/app
USER www-data
