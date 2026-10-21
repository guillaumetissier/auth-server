#!/bin/sh
# Container start-up: check configuration, wait for MySQL, create keys and schema, warm the cache.
set -eu

cd /app

for var in APP_SECRET OAUTH_ENCRYPTION_KEY TOTP_ENCRYPTION_KEY DATABASE_URL APP_ISSUER; do
    eval "value=\${$var:-}"
    if [ -z "$value" ]; then
        echo "Missing required environment variable: $var" >&2
        exit 1
    fi
done

if [ "${1:-}" = "frankenphp" ]; then
    echo "Waiting for the database..."
    attempts=0
    until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo "Database still unreachable after 60 s." >&2
            exit 1
        fi
        sleep 1
    done

    # Token signing keys live on a volume: generated on first start only.
    php bin/console app:keys:generate --if-missing

    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console cache:warmup
fi

exec "$@"
