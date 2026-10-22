# Auth Server

A sign-in server for your own applications. Users sign in **once, here** (password + code from an
authenticator app), and your applications receive a **token** proving who the user is. Your
applications never see passwords.

It is a standard OAuth 2 authorization server (Authorization Code + PKCE), with TOTP two-factor
authentication, DPoP-bound tokens (RFC 9449), audited impersonation for support staff, and optional
domains and rights.

**Contents**

1. [Words used in this guide](#1-words-used-in-this-guide)
2. [What you need to install](#2-what-you-need-to-install)
3. [Start it on your computer (development)](#3-start-it-on-your-computer-development)
4. [Everyday commands and troubleshooting](#4-everyday-commands-and-troubleshooting)
5. [Production deployment](#5-production-deployment)
6. [Using the application](#6-using-the-application)
7. [Development without Docker](#7-development-without-docker)
8. [Reference documentation](#8-reference-documentation)

---

## 1. Words used in this guide

| Word | Meaning |
|---|---|
| **Auth server** | This project. It shows the sign-in pages and issues tokens. |
| **Application** (or **client**) | A website or app of yours that lets users sign in through the auth server. Each one is registered once with an identifier (`client_id`), e.g. `my-spa`. |
| **User** | A person who signs in. Each user has a **username**, a **password** and an **external id**. |
| **External id** | The id of the user in *your* application's database (e.g. `42`). The auth server puts it in the token (claim `sub`), so your application knows which of its users signed in. While trying things out, any number works. |
| **Token** | A signed piece of text the application receives after sign-in and sends with each request to its API. The **access token** lives 15 minutes; the **refresh token** gets a new one without signing in again. |
| **2FA / TOTP** | Two-factor authentication: after the password, a 6-digit code from an authenticator app on a phone (Google Authenticator, Microsoft Authenticator, FreeOTP, 1Password, …). The code changes every 30 seconds. |
| **DPoP** | Protection that makes a stolen token useless: each request also carries a proof signed by a key that never leaves the application. |
| **Issuer** | The public address of the auth server, e.g. `http://localhost:8080` on your computer or `https://auth.example.com` in production. |
| **Docker** | A tool that runs the auth server and its database in isolated "containers", so you do not need to install PHP or MySQL yourself. |
| **Terminal** | The window where you type commands (Terminal on macOS and Linux, Ubuntu/WSL on Windows). Every command of this guide is typed there and confirmed with Enter. |

---

## 2. What you need to install

You need **Git**, **Docker** (with **Docker Compose v2**), **make** and **openssl**. PHP is **not**
needed to run the server.

### Linux (Ubuntu or Debian)

```bash
sudo apt update
sudo apt install -y git make openssl curl
curl -fsSL https://get.docker.com | sudo sh        # installs Docker and Docker Compose
sudo usermod -aG docker $USER                      # lets you use Docker without sudo
```

**Log out and log in again** (or restart) so that the last command takes effect.

### macOS

1. Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) and start it (whale icon in the menu bar).
2. In a terminal, install Git and make: `xcode-select --install`. openssl is already there.

### Windows

1. Install WSL 2 with Ubuntu: open PowerShell **as administrator**, run `wsl --install`, restart, then
   open "Ubuntu" from the Start menu and choose a Linux username and password.
2. Install [Docker Desktop](https://www.docker.com/products/docker-desktop/), and in its settings enable
   *Resources → WSL integration → Ubuntu*.
3. In the Ubuntu window: `sudo apt update && sudo apt install -y git make openssl`.

Type every command of this guide in the **Ubuntu** window, not in PowerShell.

### Check

```bash
git --version              # git version 2.x
docker compose version     # Docker Compose version v2.x   (not "docker-compose", with a dash)
docker run --rm hello-world   # prints "Hello from Docker!"
make --version             # GNU Make 3.x or 4.x
```

If one of them fails, see [Troubleshooting](#troubleshooting).

### Optional: PHP, to try tokens from the command line

Only needed for step 3.9 (seeing a token) and for the tests. On Ubuntu 24.04 or Debian 13:

```bash
sudo apt install -y php8.3-cli php8.3-mysql php8.3-sqlite3 php8.3-intl php8.3-curl \
                    php8.3-mbstring php8.3-xml php8.3-gmp unzip composer
php --version              # PHP 8.3.x
```

(Older Ubuntu versions ship an older PHP: add `ppa:ondrej/php` first. macOS: `brew install php composer`.)

---

## 3. Start it on your computer (development)

### 3.1 Download the project

```bash
git clone https://github.com/guillaumetissier/auth-server.git
cd auth-server
```

All the following commands are run **from this `auth-server` folder**.

### 3.2 Create the settings file

```bash
make secrets
```

Expected output: `Secrets written to .env.docker (ignored by git).`

This creates the file `.env.docker` with random passwords and keys. Keep it: if you delete it after
the first start, the database will refuse the new passwords (see [Troubleshooting](#troubleshooting)).
The defaults are right for your computer; nothing to edit now.

### 3.3 Start the server

```bash
make up
```

The first time, this downloads and builds everything: **allow 3 to 10 minutes**. The command returns
when the server is ready and ends with lines such as:

```
 ✔ Container auth-server-auth-db-1      Healthy
 ✔ Container auth-server-auth-server-1  Healthy
```

At its first start the server creates its signing keys and its database tables by itself.

### 3.4 Check that it answers

```bash
curl http://localhost:8080/health
```

Expected: `{"status":"ok"}`. You can also open <http://localhost:8080/health> in your browser.

### 3.5 Register an application

The auth server only accepts sign-ins coming from registered applications. Register a test one:

```bash
make client ID=my-spa REDIRECT=http://localhost:5173/callback
```

- `my-spa` is the identifier of the application.
- `http://localhost:5173/callback` is where the browser is sent back after sign-in. Nothing needs to
  run there for this test.

### 3.6 Create a user

```bash
make user USERNAME=alice EXTERNAL_ID=42
```

You are asked for a password (at least 12 characters). Nothing is displayed while you type: this is
normal. Expected: `[OK] User "alice" created (sub = 42).`

### 3.7 Sign in

1. Open <http://localhost:8080/login> in your browser.
2. Enter `alice` and her password, click **Continue**.
3. You arrive on the **account page**, showing `alice`. From there you can set up two-factor
   authentication (next step) and sign out.

### 3.8 Set up two-factor authentication

1. On the account page, click **Set up two-factor authentication**.
2. On your phone, open your authenticator app, choose "add an account", and scan the QR code shown on
   the page (or type the secret displayed under it, without the spaces).
3. Type the 6-digit code shown by the app and click **Activate**.
4. The page then shows **10 backup codes**. Each one replaces a phone code once, if you lose the phone.
   Write them down, then click **I have saved them, continue**.

From now on, signing in as alice asks for the password **and** a code from the app.

> When users sign in through an application, the server asks them to set up 2FA by itself. With the
> default setting (`APP_2FA_MODE=grace`), they can click **Later**. See
> [Migrating existing users](docs/migrating-users.md) to change this.

### 3.9 See the token an application receives (optional, needs PHP)

This step plays the role of an application from the command line, to see the whole sign-in flow.
It needs PHP (see [Optional: PHP](#optional-php-to-try-tokens-from-the-command-line)).

1. Install the PHP libraries used by the tool (once, takes a minute):

   ```bash
   composer install
   ```

2. Print a sign-in address:

   ```bash
   php tools/oauth-cli.php authorize-url
   ```

3. Copy the long address it prints (starting with `http://localhost:8080/authorize?`) into your
   browser. Sign in if asked (password, then the code of your app).
4. The browser ends on an address starting with `http://localhost:5173/callback?code=` and shows
   "This site can't be reached". **This is expected**: no application listens there. Copy the value
   after `code=` up to `&state` (excluded) from the address bar.
5. Within **5 minutes** (the code expires), exchange it for tokens:

   ```bash
   php tools/oauth-cli.php token PASTE_THE_CODE_HERE
   ```

6. Look at what the token contains, then check it as an API would:

   ```bash
   php tools/oauth-cli.php decode     # "sub": "42" is alice's external id
   php tools/oauth-cli.php call       # 200 OK user=42 client=my-spa scopes=… proof_of_possession=yes
   ```

`php tools/oauth-cli.php refresh` gets new tokens with the refresh token. `php tools/oauth-cli.php help`
lists everything.

### 3.10 Become an administrator

Administrators manage users and rights in the browser, and can impersonate users.

```bash
make role USERNAME=alice ROLE=ROLE_SUPER_ADMIN
```

Sign out and in again: the account page now has a **Users and rights** button, which opens the
administration pages (<http://localhost:8080/admin>). See [Using the application](#6-using-the-application).

You now have a working auth server. To connect a real application, go to
[For developers](#for-developers-connecting-an-application).

---

## 4. Everyday commands and troubleshooting

Run these from the `auth-server` folder.

| To… | Type |
|---|---|
| Stop the server (data is kept) | `make down` |
| Start it again | `make up` |
| See what the server writes (errors, requests); Ctrl+C to stop watching | `make logs` |
| See if it runs | `docker compose ps` |
| Apply a change made in `.env.docker` | `docker compose up -d` |
| List every `make` command | `make help` |
| **Delete everything** (users, clients, keys) and start from zero | `docker compose down -v`, then `make up` |

### Troubleshooting

| Message or symptom | Cause and fix |
|---|---|
| `make: command not found` | Install make (see [section 2](#2-what-you-need-to-install)). |
| `permission denied while trying to connect to the Docker daemon socket` | Run `sudo usermod -aG docker $USER`, then log out and in again. |
| `Cannot connect to the Docker daemon` | Docker is not started: start Docker Desktop (macOS, Windows), or `sudo systemctl start docker` (Linux). |
| `docker: 'compose' is not a docker command` | Docker Compose v2 is missing: reinstall Docker as in section 2. The old `docker-compose` is not supported. |
| `env file .env.docker not found` | Run `make secrets` first. |
| `Bind for 0.0.0.0:8080 failed: port is already allocated` | Another program uses port 8080. Use another port: set `APP_ISSUER=http://localhost:8081` in `.env.docker`, then start with `AUTH_SERVER_PORT=8081 make up`. Use `http://localhost:8081` everywhere in this guide, and prefix the tool commands with `ISSUER=http://localhost:8081`. |
| `container auth-server-auth-db-1 is unhealthy`, or `Access denied for user 'auth'` in `make logs` | The database was created with other passwords (usually `.env.docker` was deleted and recreated). If you have no data to keep: `docker compose down -v`, then `make up`. |
| `the input device is not a TTY` | You ran a `make` command from a script or cron: use `make purge` (made for it) or `docker compose exec -T auth-server php bin/console …`. |
| "Invalid credentials" on the login page | Wrong username or password. After 5 failures from the same computer, wait 15 minutes; after 10 failures in total, the account is locked for 30 minutes. |
| The 6-digit code is refused | Check the time of the phone and of the computer (automatic time on both). Each code works once. |
| `token` answers `invalid_grant` | The code is older than 5 minutes or was already used: start again from `authorize-url`. |
| `composer install` complains about a missing `ext-…` | Install the matching PHP package, e.g. `ext-pdo_mysql` → `php8.3-mysql`, `ext-pdo_sqlite` → `php8.3-sqlite3`. |
| Anything else | `make logs` shows the error. |

---

## 5. Production deployment

This section installs the auth server on an Internet-facing Linux server, at an address such as
`https://auth.example.com`. Replace `auth.example.com` and `app.example.com` by your own names.

### What you need

- A Linux server (Ubuntu 24.04 or Debian 12, 1 GB of RAM or more) you can reach with `ssh`.
- A domain name, and access to its DNS settings.
- Ports 80 and 443 open towards the server (TLS certificates are obtained on port 80).

### 5.1 Point the name to the server

At your DNS provider, create an **A record** `auth.example.com` → the IP address of the server. Check
from your computer, after a few minutes: `ping auth.example.com` shows the server's IP.

### 5.2 Install the tools on the server

Connect with `ssh`, then install Git, make, openssl and Docker exactly as in
[section 2, Linux](#linux-ubuntu-or-debian), and log out and in again.

### 5.3 Download the project and create the settings

```bash
cd ~
git clone https://github.com/guillaumetissier/auth-server.git
cd auth-server
make secrets
```

Open the settings file with a text editor (`nano .env.docker`; in nano, Ctrl+O then Enter saves,
Ctrl+X quits) and change these lines:

```dotenv
APP_ISSUER=https://auth.example.com
TRUSTED_PROXIES=PRIVATE_SUBNETS
CORS_ALLOWED_ORIGINS=https://app.example.com
TOTP_ISSUER="Example Inc."
```

- `APP_ISSUER`: the public address, with `https://` and **without** a trailing slash.
- `TRUSTED_PROXIES=PRIVATE_SUBNETS`: tells the server that requests come through the HTTPS proxy of step 5.5.
- `CORS_ALLOWED_ORIGINS`: the addresses of your browser applications, comma-separated.
- `TOTP_ISSUER`: the name users will see in their authenticator app.

Optionally, choose the 2FA policy (`APP_2FA_MODE`) and webhooks (`WEBHOOK_URLS`): see
[Configuration](docs/configuration.md).

**Back up `.env.docker`** somewhere safe (a password manager): without it, the two-factor secrets
stored in the database cannot be read.

### 5.4 Keep the server port private

The server must only be reached through HTTPS. Publish its port on the server itself only:

```bash
echo 'export AUTH_SERVER_PORT=127.0.0.1:8080' >> ~/.bashrc
source ~/.bashrc
```

(Docker publishes ports around the firewall, so this setting matters even with a firewall.)

### 5.5 Start the server

```bash
make up
docker compose ps        # the PORTS column shows 127.0.0.1:8080->8080/tcp
curl http://127.0.0.1:8080/health     # {"status":"ok"}
```

The containers restart by themselves after a reboot.

### 5.6 Add HTTPS with Caddy

Caddy is a web server that obtains and renews TLS certificates automatically.

```bash
sudo apt install -y caddy
sudo nano /etc/caddy/Caddyfile
```

Replace the content of the file by:

```
auth.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Then:

```bash
sudo systemctl reload caddy
```

Check from your computer: <https://auth.example.com/health> shows `{"status":"ok"}` with a padlock.
If not: `sudo journalctl -u caddy --since "10 minutes ago"` shows why (usually the DNS record or a
closed port 80).

(Any other reverse proxy works, e.g. nginx with certbot: forward to `127.0.0.1:8080` and set the
`X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` headers.)

### 5.7 Create the first accounts

```bash
make user USERNAME=admin EXTERNAL_ID=1
make role USERNAME=admin ROLE=ROLE_SUPER_ADMIN
make client ID=my-app REDIRECT=https://app.example.com/callback
```

Sign in at `https://auth.example.com/login` and set up two-factor authentication at once (step 3.8):
administrators need it. To bring existing users with their current passwords, see
[Migrating existing users](docs/migrating-users.md).

### 5.8 Schedule the clean-up

Expired tokens must be removed regularly. Open the scheduled tasks of your user with `crontab -e`
(choose nano if asked) and add this line:

```
0 * * * * cd $HOME/auth-server && make purge >> $HOME/auth-server-purge.log 2>&1
```

### 5.9 Back up

Back up the database every day and keep `.env.docker` with the backups: see
[Operations](docs/operations.md#data-and-backups) for the commands.

### 5.10 Upgrade

```bash
cd ~/auth-server
git pull
make up
```

Database changes are applied automatically when the server starts. Read `CHANGELOG.md` before upgrading.

### 5.11 Monitor

Have a monitoring service call `https://auth.example.com/health` every minute: it answers
`{"status":"ok"}` (HTTP 200) when the server works.

---

## 6. Using the application

### For users

| Page | Address | What for |
|---|---|---|
| Sign in | `/login` | Username, password, then the 6-digit code (or one backup code). Users usually arrive here through an application. |
| Account | `/account` | Set up or (when allowed) disable two-factor authentication, generate new backup codes, sign out. |

- **New phone**: sign in with a backup code, then on the account page disable and set up 2FA again
  (if disabling is not allowed, ask an administrator).
- **Lost phone and backup codes**: there is no recovery page yet; keep the backup codes in a safe place.
- **Too many failed attempts**: wait 15 minutes; after 10 failures the account is locked for 30 minutes.

### For administrators

Users with a platform role, or with management rights on a domain, see a **Users and rights** button on
their account page. It opens `/admin`:

- **Users**: list and search; each user's page shows his domain, rights and roles, and lets you change
  them (super-administrator, or domain managers within their own rights).
- **Domains**: the tree of organisations, their members and owners.
- **Resource types**: the kinds of objects rights apply to (vehicles, invoices, …).
- **Impersonate in …** button: act as a user in an application, after giving a reason and a fresh 2FA code.
  It appears once the application's entry point is declared, see [Impersonation](docs/impersonation.md).

Roles:

| Role | Can |
|---|---|
| `ROLE_SUPER_ADMIN` | Everything. **One** account only. |
| `ROLE_ADMIN` | See everything, change nothing, impersonate in read-only mode. |

```bash
make role USERNAME=carla ROLE=ROLE_ADMIN                   # grant
make role USERNAME=carla ROLE=ROLE_ADMIN FLAG=--remove     # remove
make role USERNAME=dave ROLE=ROLE_SUPER_ADMIN FLAG=--transfer   # hand the super-administrator role over
```

Other administration commands (creating users, importing, 2FA roll-out follow-up, impersonation audit)
are listed in [Operations](docs/operations.md). Domains and rights are explained in
[Domains and rights](docs/domains-and-rights.md), impersonation in [Impersonation](docs/impersonation.md).

### For developers: connecting an application

1. Register the application: `make client ID=my-app REDIRECT=https://app.example.com/callback`, and add
   its origin to `CORS_ALLOWED_ORIGINS`.
2. In the browser part, sign users in and call your API with the npm package
   [`@guillaumetissier/oauth-dpop-client`](packages/dpop-client-ts/README.md).
3. In your PHP API (Laravel, Symfony or any PSR-15 framework), check the tokens with
   [`guillaumetissier/resource-guard`](https://github.com/guillaumetissier/resource-guard).

The complete walkthrough, with code, is in [Connecting an application](docs/integrating.md).

Addresses an application uses (also listed at `/.well-known/oauth-authorization-server`):

| Address | Purpose |
|---|---|
| `/authorize` | Sign-in page (Authorization Code + PKCE S256) |
| `/token` | Exchanges the code, refreshes tokens (DPoP proof required by default) |
| `/.well-known/jwks.json` | Public key to verify the tokens |
| `/impersonation/end` | Ends an impersonation |
| `/health` | Health check |

---

## 7. Development without Docker

For working on the server's code with PHP only, using an SQLite file as database. Needs the PHP
packages of [section 2](#optional-php-to-try-tokens-from-the-command-line).

```bash
composer install
cat > .env.local <<'EOF'
APP_ENV=dev
APP_DEBUG=1
DATABASE_URL="sqlite:///%kernel.project_dir%/var/dev.db"
OAUTH_ENCRYPTION_KEY=change-me-dev-only-0123456789abcdef0123456789abcdef
TOTP_ENCRYPTION_KEY=ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGV2ZGU=
EOF
php bin/console app:keys:generate --if-missing
php bin/console doctrine:schema:create
php bin/console league:oauth2-server:create-client "My SPA" my-spa --public \
    --redirect-uri=http://localhost:5173/callback \
    --grant-type=authorization_code --grant-type=refresh_token --scope=profile --scope=offline_access
php bin/console app:user:create alice 42
php -S 127.0.0.1:8080 -t public public/index.php
```

The last command keeps running (Ctrl+C stops it); the server is at <http://localhost:8080>, and steps
3.7 to 3.9 work the same. Replace `make <command>` by the matching `php bin/console` command
(`make help` shows them in the `Makefile`). The keys above are for your computer only.

Tests, coding standard, static analysis and project layout: see [Development](docs/development.md).

---

## 8. Reference documentation

| Document | Content |
|---|---|
| [Configuration](docs/configuration.md) | Every setting of `.env.docker` |
| [Migrating existing users](docs/migrating-users.md) | Importing accounts with their passwords, rolling out 2FA |
| [Connecting an application](docs/integrating.md) | Browser part, API part, webhooks |
| [Domains and rights](docs/domains-and-rights.md) | Organisations, access expressions, administration, import |
| [Impersonation](docs/impersonation.md) | Who may act as whom, and how |
| [Security model](docs/security.md) | What protects users and tokens |
| [Operations](docs/operations.md) | Commands, backups, scaling |
| [Development](docs/development.md) | Tests, project layout, releasing, roadmap |

## License

MIT, see [LICENSE](LICENSE).
