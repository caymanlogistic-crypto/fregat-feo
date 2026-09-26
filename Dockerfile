FROM composer:2 AS composer_bin

FROM php:8.4-apache

RUN apt-get -o Acquire::Retries=5 update \
    && apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
        git unzip python3 python3-venv python3-pip \
        libcurl4-openssl-dev libfreetype6-dev libicu-dev \
        libjpeg62-turbo-dev libpng-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) curl gd intl mbstring mysqli pdo_mysql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json /var/www/html/composer.json

RUN cd /var/www/html \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-local.ini /usr/local/etc/php/conf.d/99-feo-local.ini

WORKDIR /var/www/html/fregat/feo
