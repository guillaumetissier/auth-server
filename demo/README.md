# Demo: a SPA and an API using the auth server

A small test setup showing the auth server at work with two applications:

| Part | Folder | Address | What it is |
|---|---|---|---|
| **SPA** | `spa/` | <http://localhost:5173> | A React page. You sign in, choose a request, and it shows the resources received, or the error and the reason of the refusal. |
| **API** | `api/` | <http://localhost:8081> | A Symfony application serving vehicles and invoices, protected by [resource-guard](https://github.com/guillaumetissier/resource-guard). |
| Auth server | `..` | <http://localhost:8080> | This project. It signs users in and issues the tokens. |

```
 Browser ── SPA (5173)
   │  1. sign in ───────────────▶ auth server (8080) ── 2. tokens bound to the browser's DPoP key
   │  3. GET /api/vehicles + token + DPoP proof ─▶ API (8081)
   │                                              └─ checks the token with the auth server's public key
   │                                                 (downloaded over the Docker network "auth-server"),
   │                                                 the DPoP proof, the scopes and the rights
   ◀── 200 + resources, or 401 / 403 + error and reason
```

Each part runs in its own container.

## 1. Start the auth server

Follow steps 3.1 to 3.4 of the [main README](../README.md#3-start-it-on-your-computer-development): the
auth server must answer at <http://localhost:8080/health> before you go on.

## 2. Create the demo data

From the `demo` folder:

```bash
cd demo
make seed
```

This creates in the auth server:

- the application (client) `my-spa`, if it does not exist yet;
- the resource types `vehicle` and `invoice`;
- the domains `a1` (Group) > `a10` (North) > `a100` (North depot), and `a1` > `a11` (South);
- five users, all with the password **`demo-password`**:

| User | Id (`sub`) | Domain | Rights | Purpose |
|---|---|---|---|---|
| `reader` | 101 | a10 | `vehicle:r` on a10 and subdomains | Reads vehicles of North, nothing else |
| `manager` | 102 | a10 | `vehicle:m invoice:r` on a10 and subdomains | Manages vehicles, reads invoices of North |
| `outsider` | 103 | none | none | Signed in, but allowed nothing |
| `support` | 100 | none | `ROLE_ADMIN` | Impersonates users, in read-only mode; sees the administration pages without changing anything |
| `admin` | 1 | none | `ROLE_SUPER_ADMIN` | Changes everything in the administration pages, including **Settings** (if no other account is super-administrator yet) |

Each user has the e-mail address `<user>@demo.test`: "Forgot your password?" on the sign-in page sends
the link to Mailpit, <http://localhost:8025>. The auth server's own pages (dashboard, profile,
administration) are at <http://localhost:8080>: sign in there as `support` to look at the users and
domains of the demo, or as `admin` to change them and the system settings.

The command can be run again: what already exists is kept. Expected end of the output:

```
admin is the super-administrator
== Domains and rights
 [OK] Imported 2 resource type(s), 4 domain(s), 2 membership(s), 2 grant(s).

Done. Open http://localhost:5173 and sign in as reader, manager, outsider or support (demo-password).
Administration: http://localhost:8080, signed in as admin (super-administrator) or support (read-only).
```

If another account is already the super-administrator (there can be only one), the seed says so and
leaves it: `make role USERNAME=admin ROLE=ROLE_SUPER_ADMIN FLAG=--transfer` in the parent folder moves
the role to `admin`.

## 3. Start the SPA and the API

```bash
make up
```

The first time, this downloads and builds both images: allow a few minutes. The command returns when
both containers run.

## 4. Use the SPA

1. Open <http://localhost:5173>.
2. Click **Sign in**. You arrive on the auth server's sign-in page.
3. Enter `reader` and `demo-password`, click **Continue**.
4. The auth server proposes to set up two-factor authentication: click **Later**.
5. You are back on the SPA. The **Session** box shows what the token says: user `101`, domain `/a1/a10/`,
   right `vehicle:r`.
6. Click the requests one by one. The **Result** box shows the status, and for a refusal the error code,
   the reason given by the API, and an explanation in plain words.

What to expect:

| Request | `reader` | `manager` | `outsider` |
|---|---|---|---|
| Public status | 200 | 200 | 200 |
| Who am I? | 200 | 200 | 200 |
| List vehicles | 200: V-1001, V-1002 (V-2001 hidden) | 200, level manage | 403 `insufficient_rights` |
| Vehicle of another domain (V-2001, in a11) | 403: outside your domain | 403: outside your domain | 403: no domain |
| Create a vehicle | 403: you hold read | 201 (simulated) | 403: no domain |
| Delete a vehicle | 403: needs manage | 200 (simulated) | 403 |
| List invoices | 403: no right on invoices | 200: F-0001 | 403 |
| Export invoices | 403 `insufficient_scope` | 403 `insufficient_scope` | 403 `insufficient_scope` |

To change user, click **Sign out**, then **Sign in**. **Sign out** also closes your session on the auth
server (you see the SPA reload), so the next sign-in asks for a username and password again.

### Break the request on purpose

The **How to send them** box changes how the SPA sends the next requests, to show what the API refuses:

| Option | Result | Why |
|---|---|---|
| Untick *Send the access token* | 401 `unauthorized` | No credentials |
| Untick *Send a DPoP proof* | 401 `invalid_dpop_proof` | The token is bound to a key: without a proof signed by that key, a stolen token is useless |
| Tick *Send the token as "Bearer"* | 401 `invalid_token` | A DPoP-bound token cannot be downgraded to a plain Bearer token |
| Tick *Tamper with the token* | 401 `invalid_token` | The signature no longer matches the auth server's key |
| Tick *Replay the previous proof*, then send the same request again | 401 `invalid_dpop_proof` | Each proof works only once |

**Reset to a correct request** puts the options back.

### Impersonation (read-only)

`support` is an administrator: he must set up two-factor authentication, and confirm each impersonation
with a code.

1. If you are signed in, click **Sign out**.
2. Open **Impersonate a user (administrators)**, type `101` (reader) and click **Sign in as this user**.
3. Sign in as `support` / `demo-password`.
4. Set up two-factor authentication: scan the QR code with an authenticator app, type the code, click
   **Activate**, then **I have saved them, continue**.
5. On the **Act on behalf of reader** page, type a reason (10 characters or more) and a **new** code from the
   app (wait for the code to change: each code works once), then click **Start**.
6. The Session box shows *Impersonated by 100 (read_only)*. Reading works; **Create a vehicle** is refused
   with 403 `impersonation_read_only`.

The **Impersonate in my-spa** button of the auth server's administration pages
(<http://localhost:8080/admin>, signed in as an administrator) opens the SPA the same way.

## 5. Stop

```bash
make down
```

The demo users, domains and rights stay in the auth server. `docker compose down -v` in the parent folder
deletes all the auth server's data.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `network auth-server declared as external, but could not be found` | The auth server is not started: run `make up` in the parent folder first. |
| `make seed` says `service "auth-server" is not running` | Same: start the auth server first. |
| The Result box says **No answer** | The API is not running (`docker compose ps` in `demo`), or the page is not opened at exactly `http://localhost:5173` (the API only accepts calls from this origin). |
| The auth server shows `invalid_client` or a redirect URI error | The client `my-spa` was registered with another redirect URI. It must be `http://localhost:5173/callback`. |
| Every request returns 401 `invalid_token` "token expired" | The access token lives 15 minutes: click **Refresh tokens**. |
| `additional_contexts` is refused, or `failed to compute cache key: "/src" not found` | Docker Compose is too old: version 2.17 or later is needed (`docker compose version`). |
| `Bind for 0.0.0.0:5173 failed: port is already allocated` | Another program uses the port (often a Vite dev server): stop it. |

## How it is built

**API** (`api/`): a plain Symfony 7.4 application.

- `config/packages/security.yaml`: the `/api` firewall uses resource-guard's authenticator. It checks the
  token signature (keys downloaded from the auth server and cached), the expiry, the client
  (`AUTH_SERVER_ALLOWED_CLIENTS`), the DPoP proof, and refuses writes during read-only impersonations.
- `src/Controller/ApiController.php`: the endpoints. They decide authorization with the `Principal`:
  `hasScope()`, `accessLevel()` and `can()` against the domain of each resource (`src/Data/Catalog.php`).
- `src/EventSubscriber/CorsSubscriber.php`: lets the SPA call the API from another origin, and exposes
  `WWW-Authenticate`.

**SPA** (`spa/`): React with Vite, using the browser client of this repository
([`packages/oauth-dpop-client`](../packages/oauth-dpop-client/README.md)), imported from its source (`vite.config.js`).

- `src/auth.js`: sign-in (PKCE), code exchange, refresh. The DPoP key is non-extractable and kept in IndexedDB.
- `src/api.js`: builds each request with its DPoP proof (and the deliberate mistakes of the options).
- `src/App.jsx`: the page; `src/explain.js`: the plain-words explanations.

### Without Docker

With the auth server started (Docker or not):

```bash
# API, on http://localhost:8081 (PHP 8.3+ and Composer)
cd api
composer install
echo 'AUTH_SERVER_JWKS_URI=http://localhost:8080/.well-known/jwks.json' > .env.local
php -S 127.0.0.1:8081 -t public

# SPA, on http://localhost:5173 (Node.js 22+), in another terminal
cd spa
npm install
npm run dev
```

For an auth server run without Docker, create the demo data with `CONSOLE="php ../bin/console" ./seed.sh`.
