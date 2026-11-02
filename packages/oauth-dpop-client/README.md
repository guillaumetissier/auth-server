# oauth-dpop-client

Browser helper for an OAuth 2 authorization server, in one dependency-free TypeScript file (`src/oauth-dpop.ts`):
Authorization Code with PKCE, DPoP proofs signed with WebCrypto, token exchange, refresh and impersonation.

The DPoP private key is generated **non-extractable**: scripts running in the page (including an XSS
payload) can ask the browser to sign with it but can never read it. Store the `CryptoKeyPair` in
IndexedDB if tokens must survive a page reload; structured cloning keeps it non-extractable.

Works in every browser with WebCrypto ECDSA P-256 (all current browsers), on HTTPS pages and on
`http://localhost`. On the API side, tokens and proofs are checked with
[resource-guard](https://github.com/guillaumetissier/resource-guard) (PHP).

## Getting it into your application

The client is not published on npm. Take it from this repository, in the form that suits your application:

| File | For | How to get it |
|---|---|---|
| `src/oauth-dpop.ts` | TypeScript projects (Vite, webpack…) | Copy the file into your project, e.g. `src/lib/oauth-dpop.ts` |
| `oauth-dpop.js` | ES module, plain JavaScript: `import { … } from './oauth-dpop.js'` or `<script type="module">` | Attached to each [GitHub release](https://github.com/guillaumetissier/auth-server/releases), or `npm run build` here (`dist/`) |
| `oauth-dpop.global.js` | Classic script, no build step: `<script src="oauth-dpop.global.js">` defines a global `OAuthDpop` | Same |

Pin the copy to a release of the auth server, and update it with the server. The demo SPA of this
repository ([`demo/spa`](../../demo/spa)) imports `src/oauth-dpop.ts` directly, see its `vite.config.js`.

## Usage

```ts
import {
  generateDpopKeyPair, buildAuthorizeUrl, exchangeCode, refreshTokens, dpopFetch,
  impersonationOf, endImpersonation, logoutUrl,
} from './oauth-dpop';   // the file copied into your project

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

// 5. Sign out: forget tokens and key, then close the server session too.
window.location.assign(logoutUrl(config, 'https://app.example.com/'));
```

### Classic script (no build step)

```html
<script src="/js/oauth-dpop.global.js"></script>
<script>
  const config = { issuer: 'https://auth.example.com', clientId: 'my-spa', redirectUri: 'https://app.example.com/callback' };

  async function signIn() {
    const keyPair = await OAuthDpop.generateDpopKeyPair();
    const { url, verifier, state } = await OAuthDpop.buildAuthorizeUrl(config);
    // … same steps as above, every function is on OAuthDpop
  }
</script>
```

### Impersonation

For administrators:

```ts
const { url } = await buildAuthorizeUrl(config, { impersonate: '43' });  // user id in your application
// … same flow as above, then:
impersonationOf(tokens);                     // { actorId, mode: 'read_only' | 'full' } or null: show a banner
await endImpersonation(config, keyPair, tokens);
```

The authorization server must allow your application's origin for CORS (auth-server: `CORS_ALLOWED_ORIGINS`).

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
| `logoutUrl(config, postLogoutRedirectUri)` | Address of the server logout, which then sends the browser back to your application |
| `impersonationOf(tokens)` | Impersonation details of an access token |
| `endImpersonation(config, keyPair, tokens)` | Ends the current impersonation |

## Development

Needs Node.js 22 or later (only for development: the client itself runs in browsers).

```bash
cd packages/oauth-dpop-client
npm install
npm test          # builds dist/ (both files), then runs the tests (Node's test runner, WebCrypto, no browser needed)
npm run typecheck
```

The CI runs them, and the release workflow attaches both built files to the GitHub release of each tag.

## License

MIT, see [LICENSE](../../LICENSE).
