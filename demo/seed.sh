#!/bin/sh
# Creates in the auth server what the demo needs: the "my-spa" client, four users, domains and rights.
# Safe to run again: what already exists is kept.
#
#   ./seed.sh                                  # auth server started with Docker Compose (parent folder)
#   CONSOLE="php ../bin/console" ./seed.sh     # auth server run without Docker
set -eu
cd "$(dirname "$0")"

PASSWORD=demo-password

if [ -n "${CONSOLE:-}" ]; then
    run() { $CONSOLE "$@"; }
    import_file="$(pwd)/seed/access.json"
else
    run() { docker compose -f ../docker-compose.yml exec -T auth-server php bin/console "$@"; }
    docker compose -f ../docker-compose.yml cp seed/access.json auth-server:/tmp/demo-access.json
    import_file=/tmp/demo-access.json
fi

echo "== Client my-spa"
run league:oauth2-server:create-client my-spa my-spa --public \
    --redirect-uri=http://localhost:5173/callback --grant-type=authorization_code --grant-type=refresh_token \
    --scope=profile --scope=offline_access >/dev/null 2>&1 && echo "created" || echo "already exists"
# The Impersonate button of the administration pages opens the SPA with ?impersonate=<user id>.
run app:client:policy my-spa '--impersonation-uri=http://localhost:5173/?impersonate={user}' >/dev/null

echo "== Users (password: $PASSWORD)"
for user in reader:101 manager:102 outsider:103 support:100; do
    name=${user%%:*}
    id=${user##*:}
    run app:user:create "$name" "$id" --password="$PASSWORD" >/dev/null 2>&1 && echo "$name created" || echo "$name already exists"
done
run app:user:role support ROLE_ADMIN >/dev/null 2>&1 || true

echo "== Domains and rights"
run app:access:import "$import_file"

echo
echo "Done. Open http://localhost:5173 and sign in as reader, manager, outsider or support ($PASSWORD)."
