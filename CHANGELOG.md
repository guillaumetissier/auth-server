# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/);
one version number covers the server image and `@guillaumetissier/oauth-dpop-client`.
`guillaumetissier/resource-guard` has its own repository and changelog.

## [Unreleased]

## [0.1.0] - 2026-10-22

First public version.

### Server
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

### Browser client
- `@guillaumetissier/oauth-dpop-client`: browser helper for PKCE, DPoP proofs, token exchange and refresh, impersonation.

### Deployment and tooling
- Docker image (FrankenPHP) and Docker Compose stack with MySQL 8.4; `make` commands for every administration task.
- Step-by-step README (installation, development, production, usage) and reference documentation in `docs/`.
- Quality checks in CI: PHPUnit functional tests, PHPStan at level max, PHP-CS-Fixer, and an end-to-end
  check over real HTTP (`tools/e2e.php`) against the Docker image and MySQL.

[Unreleased]: https://github.com/guillaumetissier/auth-server/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/guillaumetissier/auth-server/releases/tag/v0.1.0
