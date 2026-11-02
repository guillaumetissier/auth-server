// Plain-words explanation of an API answer.
export function explain(result) {
  if (result.networkError) {
    return 'The browser got no answer. Is the demo API running (make up), and does its DEMO_SPA_ORIGIN match the address of this page? Details in the browser console (F12).';
  }

  const error = result.body && typeof result.body === 'object' ? result.body.error : undefined;
  const description = String(result.body?.error_description ?? '');

  if (result.ok) {
    return 'Allowed: the token is valid, the DPoP proof matches, and you hold the rights needed.';
  }
  if (result.status === 404) {
    return 'The resource does not exist.';
  }
  if (result.status === 401) {
    if (!error || error === 'unauthorized') {
      return 'No credentials: the request carried no access token. Sign in, or tick "Send the access token".';
    }
    if (error === 'invalid_dpop_proof') {
      if (/already been used/i.test(description)) {
        return 'Replay refused: each DPoP proof can be used only once. A stolen proof cannot be sent again.';
      }
      if (/missing|required/i.test(description)) {
        return 'The token is bound to a key, so each request must carry a DPoP proof signed with that key. A stolen token alone is useless.';
      }
      return 'The DPoP proof is not valid for this request (wrong key, method, URL or token).';
    }
    if (error === 'invalid_token') {
      if (/expired/i.test(description)) {
        return 'The access token has expired (it lives 15 minutes). Click "Refresh tokens".';
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
