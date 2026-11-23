# syntax=docker/dockerfile:1.7

FROM composer:2 AS vendor

WORKDIR /app/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM php:8.4-fpm-alpine AS runtime

ARG UID=10001
ARG GID=10001

RUN apk add --no-cache \
        bash \
        curl \
        icu-libs \
        libzip \
        oniguruma \
        tini \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/99-factoryhr.ini

WORKDIR /var/www/html

COPY --from=vendor --chown=${UID}:${GID} /app/backend/vendor /var/www/html/backend/vendor
COPY --chown=${UID}:${GID} backend /var/www/html/backend
COPY --chown=${UID}:${GID} deploy /var/www/html/deploy

RUN addgroup -g "${GID}" -S app \
    && adduser -u "${UID}" -S -G app app \
    && mkdir -p /var/www/html/backend/storage/framework/{cache,sessions,views} \
        /var/www/html/backend/storage/logs \
        /var/www/html/backend/bootstrap/cache \
    && chown -R app:app /var/www/html/backend/storage /var/www/html/backend/bootstrap/cache \
    && chmod -R ug+rwX /var/www/html/backend/storage /var/www/html/backend/bootstrap/cache

USER app

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=5 \
    CMD php-fpm -t >/dev/null 2>&1 || exit 1

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm", "--nodaemonize"]
