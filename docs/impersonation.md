# Impersonation

Support staff can act on behalf of a user, with a reason, a fresh TOTP code and a time limit.

| Who | How many | Mode | May impersonate |
|---|---|---|---|
| `ROLE_ADMIN` | any number | **Read-only**: scopes reduced to `IMPERSONATION_READONLY_SCOPES`, and resource-guard refuses every write request (403 `impersonation_read_only`) | Regular users |
| `ROLE_SUPER_ADMIN` | **one** account (enforced by `make role`; `FLAG=--transfer` moves it) | **Full rights** of the user | Regular users and admins |
| Domain users holding `@impersonate` | as granted | `@impersonate:r` read-only, `@impersonate:w` full | Users of the domains where they hold it, never platform staff nor the owner of their own domain or of a domain above |

Nobody impersonates the super-administrator or himself. Domain owners do not impersonate by default:
add `@impersonate:r` (or `:w`) to the owner rights of their domain.

Grant the platform roles:

```bash
make role USERNAME=carla ROLE=ROLE_ADMIN
make role USERNAME=frank ROLE=ROLE_SUPER_ADMIN
```

## The Impersonate button

The administration pages show an **Impersonate** button on each user the viewer may impersonate. It opens
the client application, which starts the flow (only the application holds its PKCE verifier and DPoP key).
Tell the server where that entry point is:

```bash
make client-policy ID=my-app FLAG='--impersonation-uri=https://app.example.com/impersonate?user={user}'
```

## The flow

1. The client application starts a normal authorization with `impersonate=<external user id>`:
   `buildAuthorizeUrl(config, { impersonate: '43' })`.
2. The server shows a confirmation page: reason (10 to 500 characters) and a TOTP code never used before.
3. The tokens are the user's (`sub` = 43) and carry `act.sub` (the administrator, RFC 8693), `imp`
   (session id) and `imp_mode`. Access tokens live 5 minutes; refresh tokens die with the session.
4. "Back to my account": `endImpersonation(config, keyPair, tokens)` calls `POST /impersonation/end`.

Audit with `make impersonations` and the `impersonation.started` / `impersonation.ended` webhooks.
Resource servers should record `$principal->actorId()` in their own audit trail.
