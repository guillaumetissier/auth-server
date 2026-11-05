# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/);
one version number covers the server and its browser client (`packages/oauth-dpop-client`).
`guillaumetissier/resource-guard` (PHP) has its own repository and changelog.

## [Unreleased]

## [0.5.0] - 2026-11-05

### Added
- Facets of resource types: parts of a resource granted on their own, as `vehicle.pricing:w` (change the
  prices only). The most specific rule wins (facet, then its type, then `*`), a facet right of level read
  or more also gives read on its type, and facets take `-`, `r` or `w`. Resource servers map their fields
  to facets and check them with `Principal::deniedFacets()` (resource-guard 0.2).
- Administration of types and facets (`/admin/resource-types`, super-administrator): declare a facet under
  its type with a label and a description, change labels and descriptions, see where each type and facet
  is used, remove what no right names any more. `resource_facet` table (migration).
- `app:resource-type` / `make resource-type` declare facets (`CODE=vehicle.pricing`, `DESCRIPTION=…`) and
  list them with their use; `app:access:import` reads `facets` under each resource type.
- The rights preview of a user page shows the level on each facet.
- Audit log entries `resource_type.declared|described|removed` and `resource_facet.declared|described|removed`.
- Demo: facets `vehicle.pricing` and `vehicle.media`, user `pricer` (`vehicle.pricing:w`), `PATCH
  /api/vehicles/{id}` checking the fields against the facets, fields of unreadable facets hidden from
  responses, and the requests "Change the price" and "Change the model" in the SPA.

### Changed
- A type or facet still named in some rights (users' rights, owner rights of domains,
  `ACCESS_DEFAULT_EXPRESSION`) can no longer be removed, nor a type that still has facets.
- Resource type codes can no longer contain a dot: it separates a type from its facet. The migration warns
  about existing types with a dot.
- Requires `guillaumetissier/resource-guard` `^0.2`.

## [0.4.0] - 2026-11-04

### Added
- Settings page (`/admin/settings`) for the super-administrator, read-only for `ROLE_ADMIN`: DPoP required by
  default and per application, PKCE for confidential clients, token lifetimes (access, refresh,
  impersonation session and access token, within bounds), two-factor mode and deadline. Saved values
  (`system_setting` table, migration) apply at once and take precedence over `DPOP_REQUIRED_BY_DEFAULT`,
  `PKCE_FOR_CONFIDENTIAL_CLIENTS` (new), `OAUTH_ACCESS_TOKEN_TTL`, `OAUTH_REFRESH_TOKEN_TTL`,
  `IMPERSONATION_TTL`, `IMPERSONATION_ACCESS_TOKEN_TTL`, `APP_2FA_MODE` and `APP_2FA_DEADLINE`, which stay
  the defaults. Changes are written to the audit log.
- Demo: the seed creates `admin`, super-administrator (unless another account already holds the role), and
  gives every demo user an e-mail address (`<user>@demo.test`).
- Demo SPA: two tabs, "PKCE only" (plain Bearer tokens) and "PKCE + DPoP" (tokens bound to a key of the
  browser), each with its own failure scenarios, chosen one at a time:
  - sign-in: wrong or missing `code_verifier`, code used twice, unexpected `state`, no `code_challenge`,
    `code_challenge_method=plain`;
  - requests: no token, no proof, DPoP-bound token sent as Bearer, Bearer token sent with the DPoP scheme,
    tampered token, replayed proof, proof made for another request, proof signed by another key.
  Each refusal shows the error, the reason and a plain-words explanation.
- Demo API: `DEMO_API_REQUIRE_DPOP` (default `0`) to accept or refuse plain Bearer tokens.

### Changed
- PKCE may be turned off for confidential clients (setting); it stays mandatory for public clients, and
  `plain` is always refused.
- Demo SPA: the option checkboxes are replaced by single-choice scenario lists; the "Bind the tokens to this
  browser (DPoP)" checkbox is replaced by the tabs.

### Fixed
- Tests: `tests/bootstrap.php` clears `var/cache/test`, so that new services and templates are found.

## [0.2.0] - 2026-11-02

### Added
- Dashboard after sign-in: notifications (two-factor not enabled, few backup codes, no e-mail address)
  and, for administrators, account figures, last sign-ins and last domains created, limited to what they
  administer. Navigation bar on every page of a signed-in user.
- Profile page: account, e-mail address and password changes (current password required), two-factor
  status and set-up, own rights.
- Password links by e-mail (symfony/mailer, `MAILER_DSN`): "Forgot your password?" on the sign-in page
  (no account enumeration, rate-limited), links sent by administrators, invitations of new users.
  Single-use, hashed, expiring (`PASSWORD_RESET_TTL`, `PASSWORD_INVITATION_TTL`); a new password revokes
  the user's tokens and ends his other sessions. Mailpit in Docker Compose shows the e-mails in development.
- User administration: creation with invitation, username and e-mail changes, password link, two-factor
  reset (lost phone), disabling (ends sessions, revokes refresh tokens), deletion with confirmation.
  Allowed to the super-administrator and to holders of `@access:w`, within their own rights; `ROLE_ADMIN`
  stays read-only.
- Domain administration: renaming, deletion of empty domains, users per domain.
- `users.email` column (migration), `--email` option of `app:user:create` (`make user EMAIL=…`).
- Webhook events `user.updated`, `user.disabled`, `user.enabled`, `user.deleted`, `user.password_link_sent`.
- `demo/`: a React SPA and a Symfony API (resource-guard), each in its own container, with a seed script
  (client, users, domains, rights). The SPA shows the resources received or the error and the reason of each
  refusal, and can break its requests on purpose (no proof, Bearer, tampered token, replayed proof).
- Browser client `packages/oauth-dpop-client` (PKCE, DPoP proofs, refresh, impersonation), with tests, as
  TypeScript source, ES module and classic script (global `OAuthDpop`). Not published on npm: the release
  workflow attaches the files to the GitHub release.
- Logout started by an application: `GET /logout?client_id=…&post_logout_redirect_uri=…` closes the server
  session, then sends the browser back to the application (only to the origin of one of the client's
  redirect URIs). Advertised as `end_session_endpoint`; `logoutUrl()` in the browser client. The demo SPA
  uses it, so that signing out and in again asks for credentials.

### Changed
- Sign-in lands on `/dashboard` instead of `/account`.
- A disabled account, or a changed password, username or role, ends the open sessions at their next request.

### Fixed
- A super-administrator without `ROLE_ADMIN` could not open the administration pages unless he belonged
  to a domain.

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

### Deployment and tooling
- Docker image (FrankenPHP) and Docker Compose stack with MySQL 8.4; `make` commands for every administration task.
- Step-by-step README (installation, development, production, usage) and reference documentation in `docs/`.
- Quality checks in CI: PHPUnit functional tests, PHPStan at level max, PHP-CS-Fixer, and an end-to-end
  check over real HTTP (`tools/e2e.php`) against the Docker image and MySQL.

[Unreleased]: https://github.com/guillaumetissier/auth-server/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/guillaumetissier/auth-server/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/guillaumetissier/auth-server/compare/v0.2.0...v0.4.0
[0.2.0]: https://github.com/guillaumetissier/auth-server/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/guillaumetissier/auth-server/releases/tag/v0.1.0
