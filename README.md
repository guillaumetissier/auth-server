# Auth Server

A standalone OAuth 2 authorization server for first-party applications:
**Authorization Code + PKCE**, **TOTP two-factor authentication**, **DPoP**-bound tokens (RFC 9449),
and audited **impersonation** for support staff.

It stores credentials only. The `sub` claim of every token is the user's id in *your* application's
database (`external_user_id`), so your application keeps owning its users, roles and data.

```
 Browser (SPA)                          Auth Server (Docker)                    Your API (resource server)
 ─────────────                          ────────────────────                    ──────────────────────────
 1. /authorize?code_challenge=… ─────▶  login (Twig) → TOTP → code
 2. POST /token + DPoP proof ────────▶  checks PKCE + DPoP proof
    ◀──── access_token (JWT, cnf.jkt) + refresh_token (bound to the same key)
 3. GET /api/… Authorization: DPoP … + DPoP proof ─────────────────────────▶  resource-guard: signature (JWKS),
                                                                               expiry, DPoP proof, ath, jkt
                                        signed account events (webhooks) ──▶  your listeners
```

## Contents

| Path | What it is |
|---|---|
| `server/` | The authorization server: Symfony 7.4, league/oauth2-server-bundle, scheb/2fa, Twig login pages |
| `packages/dpop-client-ts/` | [`@guillaumetissier/oauth-dpop-client`](https://www.npmjs.com/package/@guillaumetissier/oauth-dpop-client): dependency-free TypeScript helper for browser applications: PKCE, DPoP proofs (WebCrypto), token exchange and refresh. See its [README](packages/dpop-client-ts/README.md) |
| `tools/` | `oauth-cli.php` (plays the browser client and a resource server from the command line) and `webhook-receiver.php`, for manual testing |
| `docker-compose.yml` | `auth-server` (FrankenPHP) and `auth-db` (MySQL 8.4) on a private network |

## Installation

| Part | Install |
|---|---|
| Server | Docker image `ghcr.io/guillaumetissier/auth-server` (tags `1`, `1.0`, `1.0.0`), or build it from this repository |
| API protection (PHP) | `composer require guillaumetissier/resource-guard` |
| Browser client | `npm install @guillaumetissier/oauth-dpop-client` |

To run the published image instead of building it, replace `build:` by
`image: ghcr.io/guillaumetissier/auth-server:1` in `docker-compose.yml`.

## Setup (development)

### With Docker (recommended)

Requirements: Docker with Compose, `make`, `openssl`. PHP 8.3+ and Composer on the host only for the
command-line test tool and the test suites.

```bash
cp .env.example .env
make secrets       # fills APP_SECRET, MySQL passwords and encryption keys with random values
make up            # builds and starts; first start generates the RSA signing keys and runs the migrations

curl http://localhost:8080/health                      # {"status":"ok"}
curl http://localhost:8080/.well-known/oauth-authorization-server
```

Register a client and a first user. `EXTERNAL_ID` is the id of that user in your application; any
value works while developing.

```bash
make client ID=my-spa REDIRECT=http://localhost:5173/callback
make user USERNAME=alice EXTERNAL_ID=42                # prompts for the password (12 characters min.)
make role USERNAME=alice ROLE=ROLE_ADMIN               # optional: lets alice impersonate users
```

Try the whole flow without writing a client, with the command-line tool:

```bash
(cd server && composer install)                # the tools use the server's dependencies
php tools/oauth-cli.php authorize-url        # open the printed URL, log in, set up 2FA (or click "Later")
# the browser ends on http://localhost:5173/callback?code=…  (nothing listens there: copy the code)
php tools/oauth-cli.php token <CODE>         # exchanges it with a DPoP proof
php tools/oauth-cli.php decode               # shows the access token claims
php tools/oauth-cli.php call                 # verifies it as a resource server would
```

Importing existing users is optional (see [Migrating existing users](#migrating-existing-users)).

### Without Docker (PHP + SQLite)

Requirements: PHP 8.3+ with `pdo_sqlite`, `sodium`, `intl`, and Composer.

```bash
cd server
composer install
cat > .env.local <<'EOF'
APP_ENV=dev
APP_DEBUG=1
DATABASE_URL="sqlite:///%kernel.project_dir%/var/dev.db"
OAUTH_ENCRYPTION_KEY=change-me-dev-only-0123456789abcdef0123456789abcdef
TOTP_ENCRYPTION_KEY=ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGU=
EOF
php bin/console app:keys:generate --if-missing
php bin/console doctrine:schema:create          # the migrations target MySQL; SQLite uses the mapping directly
php bin/console league:oauth2-server:create-client "My SPA" my-spa --public \
    --redirect-uri=http://localhost:5173/callback \
    --grant-type=authorization_code --grant-type=refresh_token --scope=profile --scope=offline_access
php bin/console app:user:create alice 42
php -S 127.0.0.1:8080 -t public public/index.php
```

The dev keys above are for local use only: production values come from `make secrets` or your secret store.

### Tests

```bash
make test        # needs PHP 8.3+ and Composer
```

The server has 30 functional tests on SQLite covering the complete flow (login, TOTP,
PKCE, DPoP-bound tokens, key-bound refresh), 2FA roll-out, import, lockout, CORS and impersonation.

## Configuration

All settings are environment variables (`.env` for Docker Compose).

| Variable | Default | Meaning |
|---|---|---|
| `APP_ISSUER` | `http://localhost:8080` | Public URL of the server, as browsers reach it. Used in metadata and in the DPoP `htu` check |
| `TRUSTED_PROXIES` | empty | Reverse proxy addresses when TLS is terminated in front of the container |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | Browser origins allowed to call `/token` and `/impersonation/end` |
| `APP_2FA_MODE` | `grace` | `optional`, `grace` or `required` (see below). Administrators always need 2FA |
| `APP_2FA_DEADLINE` | empty | In `grace` mode, date from which 2FA becomes required (e.g. `2026-12-31`). Empty: not decided yet |
| `TOTP_ISSUER` | `Auth Server` | Name shown in authenticator apps |
| `DPOP_REQUIRED_BY_DEFAULT` | `1` | Clients must present DPoP proofs. Per-client override: `make client-policy` |
| `OAUTH_ACCESS_TOKEN_TTL` / `OAUTH_REFRESH_TOKEN_TTL` | `PT15M` / `P30D` | Token lifetimes (ISO 8601 durations) |
| `IMPERSONATION_TTL` | `PT30M` | Maximum length of an impersonation session |
| `IMPERSONATION_ACCESS_TOKEN_TTL` | `PT5M` | Access token lifetime during an impersonation |
| `IMPERSONATION_READONLY_SCOPES` | `profile,offline_access` | Scopes kept in read-only impersonations |
| `WEBHOOK_URLS` / `WEBHOOK_SECRET` | empty | Endpoints receiving signed account events, and the shared HMAC secret (32+ characters) |
| `APP_SECRET`, `MYSQL_*`, `OAUTH_ENCRYPTION_KEY`, `TOTP_ENCRYPTION_KEY` | generated | Secrets, filled by `make secrets` |

## Migrating existing users

**Import** accounts with their current password hashes: nobody has to choose a new password.

```bash
# from your application, e.g.: SELECT username, id AS external_user_id, password AS password_hash FROM users
make import FILE=users.csv FLAG=--dry-run    # validates and reports, writes nothing
make import FILE=users.csv
```

The CSV needs a header row `username,external_user_id,password_hash`. Only bcrypt (`$2y$…`, e.g. Laravel)
and Argon2 hashes are accepted, never a clear-text password. The import is idempotent: rows whose
username or id already exist are skipped. bcrypt hashes are rehashed to Argon2id at the first successful login.

**Roll out 2FA** with `APP_2FA_MODE`:

| Mode | User without 2FA | Can disable 2FA |
|---|---|---|
| `optional` | Never asked; can enable it from `/account` | Yes, with a valid code |
| `grace` (default) | Enrollment page at every login, with a **Later** button valid for the session. From `APP_2FA_DEADLINE` on, no button: 2FA is required | No |
| `required` | Must enroll at the next login | No |

Leave `APP_2FA_DEADLINE` empty until the date is decided; the page then says 2FA will "soon" be required.
Once set (and the container restarted with `docker compose up -d`), it shows the date and the days left.
Follow the roll-out with `make 2fa-status` (`FLAG=--pending` lists the remaining accounts, how often they
postponed and their last login).

## Security model

- **PKCE with S256 for every client**, public and confidential; `plain` is refused.
- **DPoP**: the proof is checked at `/token` (type, asymmetric algorithm, signature, `htm`, `htu`, `iat`
  window, `jti` replay protection stored atomically). The access token carries `cnf.jkt` and the
  response says `token_type: DPoP`. The refresh token is **bound to the same key**: a stolen refresh token
  is useless without the client's private key.
- **Passwords**: Argon2id. The salt is part of each hash, hence no salt column.
- **TOTP**: secrets encrypted at rest (libsodium, `TOTP_ENCRYPTION_KEY`), enabled only after a valid code,
  codes cannot be replayed, 10 hashed single-use backup codes.
- **Brute force**: throttling per IP and username (5 attempts per 15 minutes), and account lock after
  10 failures (password or second factor) whatever the IP. Unknown user and wrong password get the same message.
- **Login pages**: CSRF tokens, `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, `Cache-Control: no-store`.
- Rotating refresh tokens, 15-minute access tokens; `password`, `implicit` and `client_credentials` grants disabled.
- Every account event is written to the `security_audit` log channel.

## Protecting an API (resource server)

Install [`guillaumetissier/resource-guard`](https://github.com/guillaumetissier/resource-guard) in the
application serving the API (`composer require guillaumetissier/resource-guard`). It validates tokens offline with
the server's JWKS and checks DPoP proofs. Laravel in short:

```php
Route::middleware('auth.oauth')->get('/api/items', ...);                     // any valid token
Route::middleware('auth.oauth:items.write')->post('/api/items', ...);        // scope required
Route::middleware('auth.oauth:no-impersonation')->post('/api/password', ...); // never while impersonated
```

Setup for Laravel, Symfony and PSR-15, the `Principal` API and webhook handling are described in the
[resource-guard README](https://github.com/guillaumetissier/resource-guard#readme).

To reach the server from another Compose project, join its network:

```yaml
services:
  app:
    networks: [default, auth-server]
networks:
  auth-server:
    external: true
```

## Browser client

```ts
import { generateDpopKeyPair, buildAuthorizeUrl, exchangeCode, dpopFetch } from '@guillaumetissier/oauth-dpop-client';

const config = { issuer: 'http://localhost:8080', clientId: 'my-spa', redirectUri: 'http://localhost:5173/callback' };
const keyPair = await generateDpopKeyPair();      // non-extractable; keep it in IndexedDB
const { url, verifier, state } = await buildAuthorizeUrl(config);
// redirect to url; then on /callback, after checking state:
const tokens = await exchangeCode(config, keyPair, code, verifier);
const response = await dpopFetch(keyPair, tokens, '/api/items');
```

## Impersonation

Support staff can act on behalf of a user, with a reason, a fresh TOTP code and a time limit.

| Role | Holders | Mode | May impersonate |
|---|---|---|---|
| `ROLE_ADMIN` | any number | **Read-only**: scopes reduced to `IMPERSONATION_READONLY_SCOPES`, and resource-guard refuses every non-GET request (403 `impersonation_read_only`) | Regular users |
| `ROLE_SUPER_ADMIN` | **one** account (enforced by `make role`; `FLAG=--transfer` moves it) | **Full rights** of the user | Regular users and admins |

Nobody impersonates the super-administrator or themselves.

1. The browser client starts a normal authorization with `impersonate=<external user id>`:
   `buildAuthorizeUrl(config, { impersonate: '43' })`.
2. The server shows a confirmation page: reason (10 to 500 characters) and a TOTP code never used before.
3. The tokens are the user's (`sub` = 43) and carry `act.sub` (the administrator, RFC 8693), `imp`
   (session id) and `imp_mode`. Access tokens live 5 minutes; refresh tokens die with the session.
4. "Back to my account": `endImpersonation(config, keyPair, tokens)` calls `POST /impersonation/end`.

Audit with `make impersonations` and the `impersonation.started` / `impersonation.ended` webhooks.
Resource servers should record `$principal->actorId()` in their own audit trail.

## Webhooks

Account events are POSTed as JSON to `WEBHOOK_URLS`, signed with HMAC-SHA256 over a timestamp and the
body (`X-Auth-Server-Signature: t=…,v1=…`), after the response is sent. resource-guard verifies them and
de-duplicates redeliveries.

`user.created`, `user.login_succeeded`, `user.login_failed`, `user.locked`, `user.two_factor_enabled`,
`user.two_factor_disabled`, `user.two_factor_postponed`, `user.roles_changed`, `impersonation.started`,
`impersonation.ended`.

Inside the server, the extension point is the Symfony event `App\Event\AccountEvent`.

## Operations

| Command | Purpose |
|---|---|
| `make purge` | Removes expired tokens and DPoP replay entries, closes expired impersonations. Schedule it hourly |
| `make 2fa-status` | 2FA roll-out progress |
| `make impersonations` | Impersonation audit; `FLAG=--end=<id>` ends a session |
| `make role` / `make client-policy` | Administrator roles, per-client DPoP policy |

- The RSA signing keys live in the `auth-keys` volume. Resource servers fetch the public key from `/.well-known/jwks.json`.
- Running several server replicas needs shared PHP sessions (`PdoSessionHandler` or Redis).
- The DPoP replay store is MySQL; a Redis implementation of `ReplayStoreInterface` (`SET key NX EX ttl`) is a one-line swap in `server/config/services.yaml`.

## Roadmap

- Server-issued DPoP nonces (RFC 9449 §8) and `dpop_jkt` at `/authorize` (§10).
- Webhook retries through Symfony Messenger.
- Password change and reset pages; consent screen for third-party clients.
- Translations of the login pages (English only today).

## Releasing

The server image and the npm package share one version number. Update `CHANGELOG.md`, then tag:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

GitHub Actions (`release.yml`) then pushes the image to GitHub Container Registry and publishes the npm
package (needs the `NPM_TOKEN` secret). `guillaumetissier/resource-guard` lives in its own repository
and has its own versions.

### Working on resource-guard and the server together

Clone both repositories side by side and point the server at the local copy (do not commit this):

```bash
cd server
composer config repositories.resource-guard '{"type": "path", "url": "../../resource-guard"}'
composer require guillaumetissier/resource-guard:@dev
```

`git checkout composer.json composer.lock && composer install` goes back to the published version.

## License

MIT, see [LICENSE](LICENSE).
