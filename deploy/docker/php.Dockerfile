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

FROM node:24-alpine AS frontend

WORKDIR /app/frontend

# Same relative API base as the SPA expects when served from the same origin.
ARG VITE_API_MODE=http
ARG VITE_API_BASE_URL=/v1
ARG GIT_SHA=unknown
ARG BUILD_TIME=unknown
ENV VITE_API_MODE=$VITE_API_MODE VITE_API_BASE_URL=$VITE_API_BASE_URL VITE_BUILD_SHA=$GIT_SHA

RUN corepack enable
COPY frontend/package.json frontend/pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
# Copy sources without clobbering the freshly installed node_modules: the
# build context contains a host node_modules that must never replace it.
COPY frontend/src ./src
COPY frontend/public ./public
COPY frontend/index.html frontend/tsconfig.json frontend/tsconfig.app.json frontend/tsconfig.node.json frontend/vite.config.ts frontend/env.d.ts ./
RUN pnpm build

# Nginx image that already contains the built SPA. Used by the `nginx` service
# so the frontend and API share an origin without a cross-image volume.
FROM nginx:1.27-alpine AS frontend-runtime

COPY --from=frontend /app/frontend/dist /usr/share/nginx/html
COPY deploy/docker/nginx.conf /etc/nginx/conf.d/default.conf

FROM php:8.4-fpm-alpine AS runtime

ARG UID=10001
ARG GID=10001
ARG GIT_SHA=unknown
ARG BUILD_TIME=unknown
ENV APP_COMMIT=$GIT_SHA APP_BUILD_TIME=$BUILD_TIME SPA_BUILD_SHA=$GIT_SHA

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
        sqlite-dev \
    && apk add --no-cache --virtual .pecl-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && apk del .build-deps .pecl-deps \
    && rm -rf /var/cache/apk/* /tmp/*

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/99-factoryhr.ini

WORKDIR /var/www/html

COPY --from=vendor --chown=${UID}:${GID} /app/backend/vendor /var/www/html/backend/vendor
COPY --chown=${UID}:${GID} backend /var/www/html/backend
COPY --chown=${UID}:${GID} deploy /var/www/html/deploy
COPY --from=frontend --chown=${UID}:${GID} /app/frontend/dist /var/www/html/frontend/dist

RUN addgroup -g "${GID}" -S app \
    && adduser -u "${UID}" -S -G app app \
    && mkdir -p /var/www/html/backend/storage/framework/cache \
        /var/www/html/backend/storage/framework/sessions \
        /var/www/html/backend/storage/framework/views \
        /var/www/html/backend/storage/logs \
        /var/www/html/backend/bootstrap/cache \
        /var/www/html/frontend-published \
    && chown -R app:app /var/www/html/backend/storage \
        /var/www/html/backend/bootstrap/cache \
        /var/www/html/frontend-published \
    && chmod -R ug+rwX /var/www/html/backend/storage \
        /var/www/html/backend/bootstrap/cache \
        /var/www/html/frontend-published

USER app

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=5 \
    CMD php-fpm -t >/dev/null 2>&1 || exit 1

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm", "--nodaemonize"]
