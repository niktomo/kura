FROM php:8.4-cli

RUN apt-get update && apt-get install -y git unzip \
    && pecl install apcu igbinary \
    && docker-php-ext-enable apcu igbinary

COPY .docker/php.ini /usr/local/etc/php/conf.d/kura.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
