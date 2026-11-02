# syntax=docker/dockerfile:1
# Demo API image: Symfony on FrankenPHP, listening on port 8080.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions opcache apcu \
 && apt-get update && apt-get install -y --no-install-recommends curl unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod \
    APP_DEBUG=0

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . ./
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && useradd --system --uid 10001 --home-dir /app app \
 && mkdir -p var /data/caddy /config/caddy \
 && chown -R app:app var /data /config

USER app
EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/api/public/status || exit 1

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
