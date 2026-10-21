# @guillaumetissier/oauth-dpop-client

Browser helper for the Auth Server, in one dependency-free TypeScript file (`src/oauth-dpop.ts`):
Authorization Code with PKCE, DPoP proofs signed with WebCrypto, token exchange, refresh and impersonation.

The DPoP private key is generated **non-extractable**: scripts running in the page (including an XSS
payload) can ask the browser to sign with it but can never read it. Store the `CryptoKeyPair` in
IndexedDB if tokens must survive a page reload; structured cloning keeps it non-extractable.

```bash
npm install @guillaumetissier/oauth-dpop-client
```

Works in every browser with WebCrypto ECDSA P-256 (all current browsers). Ships as an ES module with
TypeScript declarations.

## Usage

```ts
import {
  generateDpopKeyPair, buildAuthorizeUrl, exchangeCode, refreshTokens, dpopFetch,
  impersonationOf, endImpersonation,
} from '@guillaumetissier/oauth-dpop-client';

const config = {
  issuer: 'https://auth.example.com',
  clientId: 'my-spa',
  redirectUri: 'https://app.example.com/callback',
};

// 1. Login: keep verifier and state (sessionStorage), then redirect.
const keyPair = await generateDpopKeyPair();
const { url, verifier, state } = await buildAuthorizeUrl(config);
window.location.assign(url);

// 2. On the redirect URI: check `state`, then exchange the code.
const tokens = await exchangeCode(config, keyPair, code, verifier);

// 3. Call your API. A new proof is created for every request.
const response = await dpopFetch(keyPair, tokens, '/api/items');

// 4. Before expiry: refresh, with the same key (refresh tokens are bound to it).
const fresh = await refreshTokens(config, keyPair, tokens.refreshToken!);
```

Impersonation (administrators):

```ts
const { url } = await buildAuthorizeUrl(config, { impersonate: '43' });  // user id in your application
// … same flow as above, then:
impersonationOf(tokens);                     // { actorId, mode: 'read_only' | 'full' } or null: show a banner
await endImpersonation(config, keyPair, tokens);
```

The issuer must list your application's origin in `CORS_ALLOWED_ORIGINS`.

## API

| Function | Purpose |
|---|---|
| `generateDpopKeyPair()` | New non-extractable ECDSA P-256 key pair |
| `jwkThumbprint(keyPair)` | RFC 7638 thumbprint, the `jkt` tokens are bound to |
| `createDpopProof(keyPair, method, url, accessToken?, nonce?)` | Value of the `DPoP` header for one request |
| `createPkce()` | PKCE verifier and S256 challenge |
| `buildAuthorizeUrl(config, { impersonate? })` | Authorization URL, verifier and state |
| `exchangeCode(config, keyPair, code, verifier)` | Code → tokens |
| `refreshTokens(config, keyPair, refreshToken)` | New tokens |
| `dpopFetch(keyPair, tokens, input, init?)` | `fetch` with `Authorization: DPoP …` and a fresh proof |
| `impersonationOf(tokens)` | Impersonation details of an access token |
| `endImpersonation(config, keyPair, tokens)` | Ends the current impersonation |

## License

MIT
