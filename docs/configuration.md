# Configuration

Every setting is an environment variable.

| Where | When |
|---|---|
| `.env.docker` | With Docker Compose. `make secrets` creates it from `.env.docker.example`. Never committed. |
| `.env.local` | Without Docker. Create it yourself. Never committed. |
| `.env` | The defaults. Committed, contains no secret. Do not edit it: override in one of the files above. |

After changing `.env.docker`, apply it with `docker compose up -d` (the container is recreated, data is kept).

| Variable | Default | Meaning |
|---|---|---|
| `APP_ISSUER` | `http://localhost:8080` | Public address of the server, exactly as browsers reach it, without a trailing slash (production: `https://auth.example.com`). It appears in the metadata and is checked in every DPoP proof: a wrong value makes every token request fail. |
| `TRUSTED_PROXIES` | empty | Set when a reverse proxy (TLS) is in front of the server, so that the server knows the public scheme and host. With the proxy on the same machine and the port bound to `127.0.0.1`: `PRIVATE_SUBNETS`. |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | Comma-separated origins of the browser applications allowed to call `/token` and `/impersonation/end` (scheme, host and port, no path). |
| `APP_2FA_MODE` | `grace` | `optional`, `grace` or `required`, see [Migrating existing users](migrating-users.md). Administrators always need 2FA. |
| `APP_2FA_DEADLINE` | empty | In `grace` mode, the date from which 2FA becomes required, e.g. `2026-12-31`. Empty: not decided yet. |
| `TOTP_ISSUER` | `Auth Server` | Name shown in the authenticator apps. |
| `DPOP_REQUIRED_BY_DEFAULT` | `1` | Clients must send DPoP proofs. Per-client override: `make client-policy`. |
| `OAUTH_ACCESS_TOKEN_TTL` / `OAUTH_REFRESH_TOKEN_TTL` | `PT15M` / `P30D` | Token lifetimes, as ISO 8601 durations (`PT15M` = 15 minutes, `P30D` = 30 days). |
| `IMPERSONATION_TTL` | `PT30M` | Maximum length of an impersonation session. |
| `IMPERSONATION_ACCESS_TOKEN_TTL` | `PT5M` | Access token lifetime during an impersonation. |
| `IMPERSONATION_READONLY_SCOPES` | `profile,offline_access` | Scopes kept in read-only impersonations. |
| `ACCESS_DEFAULT_EXPRESSION` | empty | Rights every user holds on his own domain, e.g. `user:w:own`. See [Domains and rights](domains-and-rights.md). |
| `WEBHOOK_URLS` | empty | Comma-separated URLs receiving the signed account events. Empty: no webhook. |
| `WEBHOOK_SECRET` | generated | Shared secret signing the webhooks (32 characters or more); the receiver needs the same value. |
| `MYSQL_DATABASE`, `MYSQL_USER` | `auth` | Database name and user, created by the MySQL container on its first start. |
| `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | generated | Database passwords. Keep them URL-safe (hexadecimal, as `make secrets` generates), or set `DATABASE_URL` yourself. |
| `DATABASE_URL` | composed from `MYSQL_*` | Full connection string. Set it only for a database outside Docker Compose. |
| `APP_SECRET`, `OAUTH_ENCRYPTION_KEY`, `TOTP_ENCRYPTION_KEY` | generated | Secrets. **Changing `TOTP_ENCRYPTION_KEY` makes every enrolled authenticator unreadable**; changing `OAUTH_ENCRYPTION_KEY` invalidates every refresh token. |

The port published on the host is not a container setting: it is read from the shell, see
[Production deployment](../README.md#5-production-deployment).
