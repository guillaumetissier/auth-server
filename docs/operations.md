# Operations

## Commands

Run them from the project folder, with the stack started.

| Command | Purpose |
|---|---|
| `make help` | Lists every command |
| `make user USERNAME=… EXTERNAL_ID=…` | Creates a user (asks for the password) |
| `make import FILE=users.csv` | Imports existing users, see [Migrating existing users](migrating-users.md) |
| `make role USERNAME=… ROLE=ROLE_ADMIN` | Platform roles (`FLAG=--remove`, `FLAG=--transfer` for the super-administrator) |
| `make client ID=… REDIRECT=…` | Registers an application |
| `make client-policy ID=… FLAG=…` | Per-client DPoP policy and impersonation entry point |
| `make domain` / `make access` / `make resource-type` | Domains, a user's domain and rights, resource types |
| `make 2fa-status` | 2FA roll-out progress |
| `make impersonations` | Impersonation audit; `FLAG=--end=<id>` ends a session |
| `make purge` | Removes expired tokens and replay entries, closes expired impersonations. Schedule it hourly |
| `make logs` | Follows the server logs (Ctrl+C to stop following) |

## Data and backups

Two Docker volumes hold everything that must survive:

| Volume | Content | If lost |
|---|---|---|
| `auth-server_auth-db-data` | MySQL: users, 2FA secrets (encrypted), clients, tokens, domains, rights | Everything |
| `auth-server_auth-keys` | The RSA key pair signing the access tokens | Every issued token becomes invalid (users sign in again) |

Back up the database:

```bash
docker compose exec -T auth-db sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' > backup-$(date +%F).sql
```

Restore it:

```bash
docker compose exec -T auth-db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < backup-2026-10-22.sql
```

Keep `.env.docker` with the backups, in a safe place: without `TOTP_ENCRYPTION_KEY`, the 2FA secrets in
the database cannot be read.

## Scaling notes

- Running several server replicas needs shared PHP sessions (`PdoSessionHandler` or Redis).
- The DPoP replay store is MySQL; a Redis implementation of `ReplayStoreInterface` (`SET key NX EX ttl`)
  is a one-line swap in `config/services.yaml`.
