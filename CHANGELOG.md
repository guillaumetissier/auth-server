# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/);
one version number covers the server image and `@guillaumetissier/oauth-dpop-client`.
`guillaumetissier/resource-guard` has its own repository and changelog.

## [Unreleased]

### Added
- PHPStan at level max with the Symfony and Doctrine extensions (`composer stan`, `make stan`, CI job).
- End-to-end check over real HTTP (`tools/e2e.php`, `make e2e`), run in CI against the Docker image and MySQL.

### Changed
- README rewritten as a step-by-step guide (installation, development, production, usage); reference material moved to `docs/`.
- `make up` returns once the stack is healthy; `make purge` works from cron; `make secrets` makes `.env.docker` readable by its owner only.

### Fixed
- Console commands run with `docker compose exec` (`make user`, `make client`...) could not reach MySQL:
  DATABASE_URL was only composed by the start-up script. It is now composed by Symfony from the `MYSQL_*`
  variables (`.env`), for every process of the container.

### Changed
- `app:access:import` validates the file into typed records before writing anything.
- `symfony/var-exporter` is required explicitly (Doctrine lazy objects on PHP 8.3).

## [1.0.0] - unreleased

First public version.

- Authorization server: Authorization Code + PKCE (S256 mandatory), refresh tokens, RS256 JWT access tokens, JWKS and metadata endpoints.
- DPoP (RFC 9449) at the token endpoint, DPoP-bound access and refresh tokens, per-client policy.
- TOTP two-factor authentication with backup codes; roll-out modes `optional`, `grace` (with deadline) and `required`.
- Administrator impersonation (read-only `ROLE_ADMIN`, single full-access `ROLE_SUPER_ADMIN`), RFC 8693 `act` claim, audited sessions.
- Login lockout, audit log, signed webhooks, import of existing bcrypt/argon2 password hashes.
- Domains (tree) and access rights as compact expressions (`*:w tire:r user:w:own`): per-domain owners,
  `ACCESS_DEFAULT_EXPRESSION`, reserved powers `@access`, `@domain`, `@impersonate`; `dom`/`acl` token claims;
  `user.access_changed` webhook; JSON import with dry run.
- Administration pages: domains, users, rights with delegation bounds and preview, platform roles, resource
  types, Impersonate button through a per-client entry point. Impersonation by domain users holding `@impersonate`.
- `oauth-dpop-client`: browser helper for PKCE, DPoP proofs, token refresh and impersonation.
