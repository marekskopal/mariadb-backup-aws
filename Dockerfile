FROM composer:2.8.1 AS composer
FROM mlocati/php-extension-installer:2.6.1 AS php-extension-installer
FROM php:8.3.13-cli-alpine AS php

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    curl

RUN set -x \
    && apk add --no-cache \
        supervisor \
   		supercronic \
    	curl \
    	mariadb-client

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY ./docker/etc/supervisor/supervisor.conf /etc/supervisor/supervisor.conf

ENTRYPOINT /usr/bin/supervisord -c /etc/supervisor/supervisor.conf

COPY ./docker/etc/cron.d /etc/cron.d
RUN chmod 0600 /etc/cron.d/*

COPY ./docker/docker-php.ini /usr/local/etc/php/conf.d/docker-php.ini

COPY ./bin /app/bin
COPY ./src /app/src
COPY ./composer.json /app/

WORKDIR /app/

RUN rm -rf /app/vendor \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-progress --classmap-authoritative -d /app/ \
    && composer clear-cache
