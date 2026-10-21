# syntax=docker/dockerfile:1.7
#
# Keverna production image. One image runs all three services (web, horizon,
# scheduler) — they're differentiated by the KEVERNA_ROLE env var the
# container is launched with. See docker/start.sh.

# ─── Stage 1 — frontend assets (vite + tailwind) ─────────────────────────
FROM node:20-alpine AS assets

WORKDIR /app

# Copy lockfiles first so the npm install layer caches when only source
# files change. package-lock.json is checked in.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Vite pulls in resources/, public/, and vite.config.js. Copying the whole
# tree is simpler than enumerating every entrypoint.
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build

# ─── Stage 2 — composer vendor (no-dev) ──────────────────────────────────
FROM composer:2 AS vendor

WORKDIR /app

# composer install needs the autoload paths declared in composer.json plus
# the artisan stub for post-install scripts. Copy the bare minimum so this
# stage caches independently of application code.
COPY composer.json composer.lock ./
COPY database/ ./database/
COPY artisan ./artisan
COPY bootstrap/app.php ./bootstrap/app.php
COPY app/ ./app/
COPY config/ ./config/
COPY routes/ ./routes/

# Production install: no dev deps, autoloader optimised, no scripts (we'll
# run package discovery again in the runtime stage where extensions exist).
# The composer image lacks pcntl (horizon) and exif (spatie/image); the
# runtime stage installs both, so skip those platform checks here.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts \
        --ignore-platform-req=ext-pcntl \
        --ignore-platform-req=ext-exif

# ─── Stage 3 — runtime (PHP-FPM + nginx) ─────────────────────────────────
FROM php:8.3-fpm-alpine AS runtime

# nginx + tini for clean PID 1; the rest are PHP build deps that get
# discarded in the same layer to keep the image small.
RUN apk add --no-cache \
        nginx \
        tini \
        bash \
        ca-certificates \
        tzdata \
        icu-libs \
        oniguruma \
        libpng \
        libjpeg-turbo \
        libwebp \
        freetype \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        zip \
        exif \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*
# NB: no phpredis ext — the app uses the pure-PHP `predis` client
# (REDIS_CLIENT=predis in .env, predis/predis in composer). Keeps
# the image smaller and sidesteps PECL redis metadata failures that
# have bricked deploys before.

# Drop our php.ini overrides + fpm pool config into the standard locations.
COPY docker/php.ini       /usr/local/etc/php/conf.d/zz-keverna.ini
COPY docker/php-fpm.conf  /usr/local/etc/php-fpm.d/zz-keverna.conf
COPY docker/nginx.conf    /etc/nginx/nginx.conf

WORKDIR /var/www/html

# Application code. .dockerignore filters tests, .git, node_modules, etc.
COPY --chown=www-data:www-data . .

# Pull in build artefacts from the earlier stages.
COPY --from=vendor --chown=www-data:www-data /app/vendor                 ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build           ./public/build

# Storage + bootstrap/cache need to be writable by www-data. Laravel
# requires the framework subdirs to exist even if empty.
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Re-run package discovery now that all extensions are present. Skipped in
# the composer stage above so this stage controls the final autoloader.
RUN composer dump-autoload --classmap-authoritative --no-dev

# Entrypoint dispatches on KEVERNA_ROLE.
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# nginx binds 8080 by default — match in dokwe's service port mapping.
EXPOSE 8080

ENV KEVERNA_ROLE=web

# tini handles zombie reaping cleanly for both the FPM+nginx pair (web role)
# and the artisan-as-PID-1 cases (horizon/scheduler).
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/usr/local/bin/start.sh"]
