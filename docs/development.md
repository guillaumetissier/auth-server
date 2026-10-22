# Development

## Quality checks

```bash
make test        # PHPUnit: 40 functional tests on SQLite (needs PHP 8.3+ with pdo_sqlite, and Composer)
make cs          # coding standard (PHP-CS-Fixer, Symfony rules), changes nothing
make fixcs       # fixes the coding standard
make stan        # static analysis (PHPStan, level max, Symfony and Doctrine extensions)
make e2e         # end-to-end check of the running stack (after make up)
```

`make e2e` (`tools/e2e.php`) drives the running server over real HTTP, as a browser and a client would:
login with CSRF, 2FA enrollment then TOTP, authorization code with PKCE, DPoP-bound tokens checked by
resource-guard, domain rights in the token, refresh-token rotation and key binding, CORS, read-only
impersonation and its end, and the attacks that must fail. It creates its own client and users, so it can
run again on the same database. The CI runs it against the Docker image and MySQL.

## Project layout

| Path | What it is |
|---|---|
| `src/`, `config/`, `templates/`, `migrations/`, `tests/` | The Symfony 7.4 application (league/oauth2-server-bundle, scheb/2fa, Twig pages) |
| `docker/`, `Dockerfile`, `docker-compose.yml` | Image (FrankenPHP), PHP settings, start-up script; the stack with MySQL 8.4 |
| `packages/dpop-client-ts/` | Browser helper published on npm as `@guillaumetissier/oauth-dpop-client` |
| `tools/` | `oauth-cli.php` (manual testing), `e2e.php` (end-to-end check), `webhook-receiver.php` |

## Working on resource-guard and the server together

Clone both repositories side by side and point the server at the local copy (do not commit this):

```bash
composer config repositories.resource-guard '{"type": "path", "url": "../resource-guard"}'
composer require guillaumetissier/resource-guard:@dev
```

`git checkout composer.json composer.lock && composer install` goes back to the published version.

## Releasing

The server image and the npm package share one version number. Update `CHANGELOG.md`, then tag:

```bash
git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0
```

GitHub Actions (`release.yml`) pushes the image to GitHub Container Registry and publishes the npm package
(needs the `NPM_TOKEN` secret). `guillaumetissier/resource-guard` lives in its own repository.

## Roadmap

- Server-issued DPoP nonces (RFC 9449 §8) and `dpop_jkt` at `/authorize` (§10).
- Webhook retries through Symfony Messenger.
- Password change and reset pages; consent screen for third-party clients.
- Recovery of an account whose phone and backup codes are lost (administrator 2FA reset).
- Translations of the login pages (English only today).
