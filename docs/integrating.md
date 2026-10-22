# Connecting an application

An application uses the auth server in two places:

- **the browser part** signs users in and obtains tokens (a single-page application, or the front-end of
  your site);
- **the API** receives requests carrying those tokens and checks them.

## 1. Register the application as a client

```bash
make client ID=my-app REDIRECT=https://app.example.com/callback
```

- `ID`: the identifier of the application (`client_id`), chosen by you.
- `REDIRECT`: the page of your application where the user comes back after signing in, with the
  authorization code. It must match exactly what the application sends.
- Add the application's origin (`https://app.example.com`) to `CORS_ALLOWED_ORIGINS`.

The client is public (no secret): this is the right type for browser applications, protected by PKCE and DPoP.

## 2. Browser part

```bash
npm install @guillaumetissier/oauth-dpop-client
```

```ts
import { generateDpopKeyPair, buildAuthorizeUrl, exchangeCode, dpopFetch } from '@guillaumetissier/oauth-dpop-client';

const config = { issuer: 'https://auth.example.com', clientId: 'my-app', redirectUri: 'https://app.example.com/callback' };
const keyPair = await generateDpopKeyPair();      // non-extractable; keep it in IndexedDB
const { url, verifier, state } = await buildAuthorizeUrl(config);
// keep verifier and state (sessionStorage), redirect to url; then on /callback, after checking state:
const tokens = await exchangeCode(config, keyPair, code, verifier);
const response = await dpopFetch(keyPair, tokens, '/api/items');
```

Details: [packages/dpop-client-ts/README.md](../packages/dpop-client-ts/README.md).

## 3. API part

Install [`guillaumetissier/resource-guard`](https://github.com/guillaumetissier/resource-guard) in the
application serving the API:

```bash
composer require guillaumetissier/resource-guard
```

It checks the tokens without calling the auth server for each request (it downloads the public key once
from `/.well-known/jwks.json`) and verifies the DPoP proofs. With Laravel:

```dotenv
AUTH_SERVER_JWKS_URI=http://auth-server:8080/.well-known/jwks.json
AUTH_SERVER_ALLOWED_CLIENTS=my-app
```

```php
Route::middleware('auth.oauth')->get('/api/items', ...);                      // any valid token
Route::middleware('auth.oauth:items.write')->post('/api/items', ...);         // scope required
Route::middleware('auth.oauth:no-impersonation')->post('/api/password', ...); // never while impersonated
```

Symfony, PSR-15, the `Principal` API and access rights: see the
[resource-guard README](https://github.com/guillaumetissier/resource-guard#readme).

### Reaching the server from another Docker Compose project

The auth server's stack creates a network called `auth-server`. Join it from your application's
`docker-compose.yml`, then use `http://auth-server:8080` as address inside Docker:

```yaml
services:
  app:
    networks: [default, auth-server]
networks:
  auth-server:
    external: true
```

## 4. Webhooks (optional)

The server can notify your application of account events. Set `WEBHOOK_URLS` to your endpoint and give
the receiver the same `WEBHOOK_SECRET`. Events are POSTed as JSON, signed with HMAC-SHA256 over a timestamp
and the body (`X-Auth-Server-Signature: t=…,v1=…`), after the response is sent. resource-guard verifies
them and ignores redeliveries.

Events: `user.created`, `user.login_succeeded`, `user.login_failed`, `user.locked`,
`user.two_factor_enabled`, `user.two_factor_disabled`, `user.two_factor_postponed`, `user.roles_changed`,
`user.access_changed`, `impersonation.started`, `impersonation.ended`.

To see them while developing: `WEBHOOK_SECRET=<value from .env.docker> php -S 0.0.0.0:9000 tools/webhook-receiver.php`
and `WEBHOOK_URLS=http://host.docker.internal:9000/`.

Inside the server, the extension point is the Symfony event `App\Event\AccountEvent`.
