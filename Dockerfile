# syntax=docker/dockerfile:1

FROM composer:2 AS composer_bin

FROM php:8.4-apache

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    set -eux; \
    rm -f /etc/apt/apt.conf.d/docker-clean; \
    for attempt in 1 2 3; do \
        if apt-get -o Acquire::Retries=5 update \
            && apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
                git unzip python3 python3-venv python3-pip \
                libfreetype6-dev libicu-dev \
                libjpeg62-turbo-dev libpng-dev libzip-dev; then \
            break; \
        fi; \
        if [ "$attempt" = 3 ]; then exit 1; fi; \
        sleep 5; \
    done; \
    docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd intl mysqli pdo_mysql zip \
    && a2enmod rewrite headers

COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json /var/www/html/composer.json

RUN cd /var/www/html \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-local.ini /usr/local/etc/php/conf.d/99-feo-local.ini

WORKDIR /var/www/html/fregat/feo
