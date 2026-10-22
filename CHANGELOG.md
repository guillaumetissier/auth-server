# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/);
one version number covers the server image and `@guillaumetissier/oauth-dpop-client`.
`guillaumetissier/resource-guard` has its own repository and changelog.

## [Unreleased]

## [1.0.0] - unreleased

First public version.

- Authorization server: Authorization Code + PKCE (S256 mandatory), refresh tokens, RS256 JWT access tokens, JWKS and metadata endpoints.
- DPoP (RFC 9449) at the token endpoint, DPoP-bound access and refresh tokens, per-client policy.
- TOTP two-factor authentication with backup codes; roll-out modes `optional`, `grace` (with deadline) and `required`.
- Administrator impersonation (read-only `ROLE_ADMIN`, single full-access `ROLE_SUPER_ADMIN`), RFC 8693 `act` claim, audited sessions.
- Login lockout, audit log, signed webhooks, import of existing bcrypt/argon2 password hashes.
- `oauth-dpop-client`: browser helper for PKCE, DPoP proofs, token refresh and impersonation.
