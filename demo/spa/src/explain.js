// Plain-words explanation of an API answer.
export function explain(result) {
  if (result.networkError) {
    return 'The browser got no answer. Is the demo API running (make up), and does its DEMO_SPA_ORIGIN match the address of this page? Details in the browser console (F12).';
  }

  const error = result.body && typeof result.body === 'object' ? result.body.error : undefined;
  const description = String(result.body?.error_description ?? '');

  if (result.ok) {
    return result.sent.Authorization?.startsWith('Bearer')
      ? 'Allowed with a Bearer token: valid, and you hold the rights needed. But nothing ties it to you: whoever copies it can use it until it expires.'
      : 'Allowed: the token is valid, the DPoP proof matches, and you hold the rights needed.';
  }
  if (result.status === 404) {
    return 'The resource does not exist.';
  }
  if (result.status === 401) {
    if (!error || error === 'unauthorized') {
      return 'No credentials: the request carried no access token. Sign in, or choose "Correct request".';
    }
    if (error === 'invalid_dpop_proof') {
      if (/already been used/i.test(description)) {
        return 'Replay refused: each DPoP proof can be used only once. A stolen proof cannot be sent again.';
      }
      if (/missing|required/i.test(description)) {
        return 'The token is bound to a key, so each request must carry a DPoP proof signed with that key. A stolen token alone is useless.';
      }
      if (/key bound/i.test(description)) {
        return 'The proof is signed by another key than the one the token is bound to: what a thief holding the token, but not the private key of this browser, would send.';
      }
      if (/htm|htu/i.test(description)) {
        return 'The proof was made for another request (method or address): a proof captured on one request cannot be used on another.';
      }
      return 'The DPoP proof is not valid for this request (wrong key, method, URL or token).';
    }
    if (error === 'invalid_token') {
      if (/expired/i.test(description)) {
        return 'The access token has expired (it lives 15 minutes). Click "Refresh tokens".';
      }
      if (/DPoP-bound token required/i.test(description)) {
        return 'This API only accepts DPoP-bound tokens, and this one is a plain Bearer token (DEMO_API_REQUIRE_DPOP).';
      }
      if (/not DPoP-bound/i.test(description)) {
        return 'The token was sent with the "DPoP" scheme, but it is a plain Bearer token: it is bound to no key, so no proof could match it.';
      }
      if (/Bearer/i.test(description)) {
        return 'A DPoP-bound token must be sent with the "DPoP" scheme and a proof, never as a plain Bearer token.';
      }
      if (/signature/i.test(description)) {
        return 'The token signature does not match the auth server keys: the token was modified or forged.';
      }
      if (/client/i.test(description)) {
        return 'The token was issued to an application this API does not accept (AUTH_SERVER_ALLOWED_CLIENTS).';
      }
      return 'The access token is not valid.';
    }
  }
  if (result.status === 403) {
    if (error === 'impersonation_read_only') {
      return 'You act on behalf of a user in read-only mode: every write request is refused.';
    }
    if (error === 'insufficient_scope') {
      return 'Authenticated, but the token lacks a scope. Scopes are granted to the application (the client), not to the user.';
    }
    if (error === 'insufficient_rights') {
      return 'Authenticated, but your rights do not allow this. Rights are granted per resource type and domain in the auth server.';
    }
    return 'Authenticated, but not allowed.';
  }
  return 'Unexpected answer from the API.';
}

// Plain-words explanation of a refused sign-in (SignInError of auth.js).
export function explainSignIn(failure) {
  if (failure.error === 'invalid_dpop_proof') {
    return 'The auth server requires DPoP for this application: Bearer tokens are refused. An administrator can change this in Settings, or use the "PKCE + DPoP" tab.';
  }
  switch (failure.scenario) {
    case 'wrong_verifier':
      return 'The auth server hashed the code_verifier it received and compared the result with the code_challenge sent at the start: they differ. The verifier never left this tab, so whoever steals the code from the address bar cannot use it.';
    case 'no_verifier':
      return 'The code was issued for a code_challenge: the auth server exchanges it only with the matching code_verifier.';
    case 'reuse_code':
      return 'The first exchange of the code gave tokens; the second one is refused. A code works once: intercepted after use, it is worthless.';
    case 'wrong_state':
      return 'The answer carries a state this tab did not send: it may come from a sign-in started by someone else (an attacker trying to sign you into his own account). The SPA drops it without using the code.';
    case 'no_challenge':
    case 'plain':
      return 'The auth server refused the sign-in on its own page: PKCE with the S256 method is required. "plain" would put the verifier itself in the address, readable by anyone who sees it.';
    default:
      return 'The auth server refused the sign-in.';
  }
}
