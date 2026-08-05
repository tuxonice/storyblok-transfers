FROM php:8.3-cli-alpine

ARG UID=1001
ARG GID=1001

RUN apk add --no-cache git unzip libzip-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install zip \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The 128M CLI default is not enough for phpstan or coverage runs.
RUN printf 'memory_limit = 512M\n' > /usr/local/etc/php/conf.d/zz-dev.ini

RUN addgroup -g ${GID} app \
    && adduser -u ${UID} -G app -s /bin/sh -D app

ENV COMPOSER_HOME=/composer \
    COMPOSER_MEMORY_LIMIT=-1 \
    XDEBUG_MODE=off

# Pre-create with app ownership so the named cache volume inherits it.
RUN mkdir -p /composer && chown -R app:app /composer

WORKDIR /app

USER app
