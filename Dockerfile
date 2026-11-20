# syntax=docker/dockerfile:1
# The panel: the Laravel application on FrankenPHP. The Rust service that does the Docker and
# file work is its own image, built from service/, so it can also run on another host.

ARG PHP_VERSION=8.5
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-bookworm-slim AS node

# Dependencies and frontend assets. Debian rather than Alpine because the frontend toolchain
# ships glibc binaries, and with PHP on board because the Vite build calls `php artisan` to
# generate the route helpers.
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Manifests first, so the dependency layers are reused until they change.
COPY panel/composer.json panel/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY panel/package.json panel/package-lock.json panel/.npmrc ./
RUN npm ci

COPY panel .
# The application name is compiled into the frontend (page titles). After the build only
# public/build is served, so the frontend's sources and settings stay out of the image.
ARG APP_NAME="Spawnpoint"
RUN composer dump-autoload --no-dev --optimize \
    && php artisan wayfinder:generate --with-form \
    && VITE_APP_NAME="${APP_NAME}" npm run build \
    && rm -rf node_modules resources/js resources/css package.json package-lock.json .npmrc \
        pnpm-workspace.yaml tsconfig.json vite.config.ts components.json AGENTS.md


FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS assembled

# pcntl lets the queue worker finish its job before it stops. The debugger and the CGI build of
# PHP are 29 MB each and nothing here runs them; PHP's source and headers only serve to
# compile extensions, which is done by then.
RUN install-php-extensions pdo_mysql pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -r /usr/local/bin/phpdbg /usr/local/bin/php-cgi /usr/local/bin/phpize \
        /usr/local/bin/php-config /usr/src/php.tar.xz* /usr/local/include/php

WORKDIR /app
COPY --from=build /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-spawnpoint.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

# Unprivileged user: port 8080 needs no capability; Caddy and `artisan tinker` write to /data
# and /config. The chmod matters when the image is built from a checkout that marks every
# file writable, as Windows does.
RUN adduser -D -H -u 1000 app \
    && setcap -r /usr/local/bin/frankenphp \
    && chmod -R go-w . \
    && mkdir -p /proxy/maps storage/app/private/portal \
    && chown -R app:app storage bootstrap/cache /data /config /proxy


# The base image declares ports 80, 443 and 2019, and a Dockerfile cannot take a declared port
# away. Copying the files into an empty image leaves all of the base image's settings behind,
# so the ones that are needed are set again here.
FROM scratch AS runtime

COPY --from=assembled / /

# From the base image: where PHP, Caddy and tinker look for their files.
ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    PHP_INI_DIR=/usr/local/etc/php \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    GODEBUG=cgocheck=0

# The node is the Rust service; "node" is its name in the Compose file. The browser reaches it
# through this image's web server at /node, for the console and file transfers.
ENV APP_NAME="Spawnpoint" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PANEL_NODE_URL=http://node:8080 \
    PANEL_NODE_PUBLIC_URL=/node

WORKDIR /app
USER app

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://localhost:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
