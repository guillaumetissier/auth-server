# syntax=docker/dockerfile:1
#
# Auth server image. Build context = repository root:
#   docker build -f server/Dockerfile -t auth-server .

ARG PHP_VERSION=8.4

########## Base: FrankenPHP (Caddy + PHP in one process) ##########
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS base

RUN install-php-extensions pdo_mysql intl opcache apcu zip \
 && apt-get update && apt-get install -y --no-install-recommends curl \
 && rm -rf /var/lib/apt/lists/*

COPY server/docker/php.ini $PHP_INI_DIR/conf.d/zz-auth-server.ini
COPY server/docker/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app

########## Dependencies (cached layer: only invalidated when composer.json changes) ##########
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY server/composer.json server/composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY server/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && rm -rf tests phpunit.xml.dist .env.test var

########## Runtime ##########
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:8080

COPY --from=vendor /app /app
COPY server/docker/entrypoint.sh /usr/local/bin/entrypoint

# Unprivileged runtime user; Caddy only needs to write its data/config dirs.
RUN useradd --system --uid 10001 --home-dir /app app \
 && mkdir -p var/cache var/log var/keys /data/caddy /config/caddy \
 && chown -R app:app var /data /config \
 && chmod +x /usr/local/bin/entrypoint

USER app

EXPOSE 8080
VOLUME ["/app/var/keys"]

HEALTHCHECK --interval=10s --timeout=3s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/health || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
