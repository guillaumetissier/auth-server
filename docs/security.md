# Security model

- **PKCE with S256 for every client**, public and confidential; `plain` is refused.
- **DPoP** (RFC 9449): the proof is checked at `/token` (type, asymmetric algorithm, signature, `htm`,
  `htu`, `iat` window, `jti` replay protection stored atomically). The access token carries `cnf.jkt` and
  the response says `token_type: DPoP`. The refresh token is **bound to the same key**: a stolen refresh
  token is useless without the client's private key.
- **Passwords**: Argon2id. The salt is part of each hash, hence no salt column.
- **TOTP**: secrets encrypted at rest (libsodium, `TOTP_ENCRYPTION_KEY`), enabled only after a valid code,
  codes cannot be replayed, 10 hashed single-use backup codes.
- **Brute force**: throttling per IP and username (5 attempts per 15 minutes), and account lock after
  10 failures (password or second factor) whatever the IP. Unknown user and wrong password get the same message.
- **Login pages**: CSRF tokens, `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, `Cache-Control: no-store`.
- Rotating refresh tokens, 15-minute access tokens; `password`, `implicit` and `client_credentials` grants disabled.
- **Impersonation**: reason and fresh TOTP code required, time-limited, recorded; read-only by default.
- **Domains**: a user never gets access above or beside his domain; nobody grants more than he holds.
- Every account event is written to the `security_audit` log channel.
