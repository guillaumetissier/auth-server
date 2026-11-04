// Sign-in with the auth server: Authorization Code + PKCE, with DPoP-bound tokens (recommended) or, to show
// the difference, plain Bearer tokens. A sign-in scenario (scenarios.js) can break the flow on purpose.
//
// - The PKCE verifier and the state live in sessionStorage during the redirect to the auth server.
// - The DPoP key pair is non-extractable and kept in IndexedDB, so a page reload keeps the session.
// - Tokens live in sessionStorage (gone when the tab is closed).
// - Bearer mode: the token request carries no DPoP proof. The auth server answers with a Bearer token
//   only when DPoP is not required (Settings page, or the application's own rule).
import {
  buildAuthorizeUrl,
  createDpopProof,
  createPkce,
  exchangeCode,
  generateDpopKeyPair,
  logoutUrl,
  refreshTokens,
} from 'oauth-dpop-client';
import { config } from './config.js';

const PENDING = 'demo.pending-login';
const TOKENS = 'demo.tokens';

/** A refused sign-in: who refused it, the OAuth error code and its description. */
export class SignInError extends Error {
  constructor(source, error, description, scenario = 'correct') {
    super(`${error}: ${description}`);
    this.source = source; // 'auth server' or 'SPA'
    this.error = error;
    this.description = description;
    this.scenario = scenario;
  }
}

// --- IndexedDB storage of the key pair ------------------------------------------------------

function db() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('demo-spa', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('keys');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function keyStore(mode, action) {
  const database = await db();
  return new Promise((resolve, reject) => {
    const request = action(database.transaction('keys', mode).objectStore('keys'));
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

const loadKeyPair = () => keyStore('readonly', (store) => store.get('dpop'));
const saveKeyPair = (keyPair) => keyStore('readwrite', (store) => store.put(keyPair, 'dpop'));
const deleteKeyPair = () => keyStore('readwrite', (store) => store.delete('dpop'));

// --- Flow -----------------------------------------------------------------------------------

let redirecting = false;

/**
 * Sends the browser to the auth server's sign-in page.
 * `impersonate`: external id of a user (administrators). `dpop`: false for plain Bearer tokens.
 * `scenario`: id of a sign-in scenario, to break the flow on purpose.
 */
export async function login(impersonate, { dpop = true, scenario = 'correct' } = {}) {
  if (redirecting) {
    return; // Already leaving (React StrictMode runs effects twice in development).
  }
  redirecting = true;
  const { url, verifier, state } = await buildAuthorizeUrl(config, impersonate ? { impersonate } : {});

  const authorize = new URL(url);
  if (scenario === 'no_challenge') {
    authorize.searchParams.delete('code_challenge');
    authorize.searchParams.delete('code_challenge_method');
  } else if (scenario === 'plain') {
    // "plain": the challenge is the verifier itself, so anyone who reads the address knows the verifier.
    authorize.searchParams.set('code_challenge', verifier);
    authorize.searchParams.set('code_challenge_method', 'plain');
  }
  // "wrong_state": this tab remembers another state than the one sent, as if the answer came from another sign-in.
  const expectedState = scenario === 'wrong_state' ? (await createPkce()).verifier.slice(0, 22) : state;

  sessionStorage.setItem(PENDING, JSON.stringify({ verifier, state: expectedState, dpop, scenario }));
  window.location.assign(authorize.toString());
}

/**
 * The sign-in started from this tab that did not come back to /callback (scenarios where the auth server
 * refuses on its own page), or null. Forgets it.
 */
export function abandonedLogin() {
  const pending = JSON.parse(sessionStorage.getItem(PENDING) ?? 'null');
  sessionStorage.removeItem(PENDING);
  return pending;
}

let callbackPromise = null;

/**
 * On /callback: checks the state and exchanges the code for tokens. Runs once even if called twice
 * (React StrictMode runs effects twice in development, and a code can only be used once).
 */
export function handleCallback() {
  callbackPromise ??= (async () => {
    const params = new URLSearchParams(window.location.search);
    window.history.replaceState(null, '', '/');

    const pending = JSON.parse(sessionStorage.getItem(PENDING) ?? 'null');
    sessionStorage.removeItem(PENDING);
    const scenario = pending?.scenario ?? 'correct';

    if (params.has('error')) {
      throw new SignInError('auth server', params.get('error'), params.get('error_description') ?? '', scenario);
    }
    if (!pending || pending.state !== params.get('state')) {
      throw new SignInError(
        'SPA',
        'state_mismatch',
        'The state of the answer is not the one this tab sent: the code is not used.',
        scenario,
      );
    }

    const code = params.get('code') ?? '';
    const keyPair = pending.dpop === false ? null : await generateDpopKeyPair();
    const exchange = { grant_type: 'authorization_code', code, redirect_uri: config.redirectUri };

    let tokens;
    if (scenario === 'wrong_verifier') {
      tokens = await tokenRequest(keyPair, { ...exchange, code_verifier: (await createPkce()).verifier }, scenario);
    } else if (scenario === 'no_verifier') {
      tokens = await tokenRequest(keyPair, exchange, scenario);
    } else {
      tokens = null === keyPair
        ? await tokenRequest(null, { ...exchange, code_verifier: pending.verifier }, scenario)
        : await libraryCall(() => exchangeCode(config, keyPair, code, pending.verifier), scenario);
      if (scenario === 'reuse_code') {
        // The first exchange worked: try the same code again.
        tokens = await tokenRequest(keyPair, { ...exchange, code_verifier: pending.verifier }, scenario);
      }
    }

    if (keyPair) {
      await saveKeyPair(keyPair);
    }
    sessionStorage.setItem(TOKENS, JSON.stringify(tokens));
    return { keyPair, tokens };
  })();
  return callbackPromise;
}

/** The current session, or null. Bearer sessions have no key pair. */
export async function loadSession() {
  const tokens = JSON.parse(sessionStorage.getItem(TOKENS) ?? 'null');
  if (tokens?.tokenType === 'Bearer') {
    return { tokens, keyPair: null };
  }
  const keyPair = tokens ? await loadKeyPair() : null;
  return tokens && keyPair ? { tokens, keyPair } : null;
}

/** New tokens with the refresh token (bound to the same key, for DPoP tokens). */
export async function refresh(session) {
  const tokens = null === session.keyPair
    ? await tokenRequest(null, { grant_type: 'refresh_token', refresh_token: session.tokens.refreshToken })
    : await refreshTokens(config, session.keyPair, session.tokens.refreshToken);
  sessionStorage.setItem(TOKENS, JSON.stringify(tokens));
  return { ...session, tokens };
}

/**
 * Forgets the tokens and the key in this browser, then closes the auth server session too: otherwise
 * the next "Sign in" would reuse it without asking for credentials. The server sends the browser back here.
 */
export async function logout() {
  sessionStorage.removeItem(TOKENS);
  await deleteKeyPair();
  window.location.assign(logoutUrl(config, `${window.location.origin}/`));
}

/**
 * Token request built by hand, for Bearer tokens (no proof) and for the broken scenarios, where the library
 * would not let us send a wrong or missing verifier. With a key pair, it carries a DPoP proof like the library.
 */
async function tokenRequest(keyPair, body, scenario = 'correct') {
  const tokenUrl = `${config.issuer}/token`;
  const headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
  if (keyPair) {
    headers.DPoP = await createDpopProof(keyPair, 'POST', tokenUrl);
  }
  const response = await fetch(tokenUrl, {
    method: 'POST',
    headers,
    body: new URLSearchParams({ client_id: config.clientId, ...body }),
  });
  const json = await response.json();
  if (!response.ok) {
    throw new SignInError('auth server', json.error ?? 'token_error', String(json.error_description ?? response.status), scenario);
  }
  return {
    accessToken: json.access_token,
    refreshToken: json.refresh_token,
    expiresAt: Date.now() + json.expires_in * 1000,
    tokenType: json.token_type,
  };
}

/** Turns the library's "error: description" message into a SignInError. */
async function libraryCall(call, scenario) {
  try {
    return await call();
  } catch (error) {
    const [code, ...rest] = String(error.message).split(': ');
    throw new SignInError('auth server', code, rest.join(': '), scenario);
  }
}

/** Claims of a JWT, for display only (the API verifies the signature, not the SPA). */
export function decodeJwt(jwt) {
  const part = jwt.split('.')[1] ?? '';
  const json = atob(part.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(part.length / 4) * 4, '='));
  return JSON.parse(new TextDecoder().decode(Uint8Array.from(json, (c) => c.charCodeAt(0))));
}
