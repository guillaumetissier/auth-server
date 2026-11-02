// Sign-in with the auth server: Authorization Code + PKCE, DPoP-bound tokens.
//
// - The PKCE verifier and the state live in sessionStorage during the redirect to the auth server.
// - The DPoP key pair is non-extractable and kept in IndexedDB, so a page reload keeps the session.
// - Tokens live in sessionStorage (gone when the tab is closed).
import { buildAuthorizeUrl, exchangeCode, generateDpopKeyPair, logoutUrl, refreshTokens } from 'oauth-dpop-client';
import { config } from './config.js';

const PENDING = 'demo.pending-login';
const TOKENS = 'demo.tokens';

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

/** Sends the browser to the auth server's sign-in page. `impersonate`: external id of a user (administrators). */
let redirecting = false;

export async function login(impersonate) {
  if (redirecting) {
    return; // Already leaving (React StrictMode runs effects twice in development).
  }
  redirecting = true;
  const { url, verifier, state } = await buildAuthorizeUrl(config, impersonate ? { impersonate } : {});
  sessionStorage.setItem(PENDING, JSON.stringify({ verifier, state }));
  window.location.assign(url);
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

    if (params.has('error')) {
      throw new Error(`The auth server refused the sign-in: ${params.get('error')} ${params.get('error_description') ?? ''}`);
    }
    const pending = JSON.parse(sessionStorage.getItem(PENDING) ?? 'null');
    sessionStorage.removeItem(PENDING);
    if (!pending || pending.state !== params.get('state')) {
      throw new Error('Sign-in answer not expected by this tab (state mismatch): start again with "Sign in".');
    }

    const keyPair = await generateDpopKeyPair();
    const tokens = await exchangeCode(config, keyPair, params.get('code') ?? '', pending.verifier);
    await saveKeyPair(keyPair);
    sessionStorage.setItem(TOKENS, JSON.stringify(tokens));
    return { keyPair, tokens };
  })();
  return callbackPromise;
}

/** The current session, or null. */
export async function loadSession() {
  const tokens = JSON.parse(sessionStorage.getItem(TOKENS) ?? 'null');
  const keyPair = tokens ? await loadKeyPair() : null;
  return tokens && keyPair ? { tokens, keyPair } : null;
}

/** New tokens with the refresh token (bound to the same key). */
export async function refresh(session) {
  const tokens = await refreshTokens(config, session.keyPair, session.tokens.refreshToken);
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

/** Claims of a JWT, for display only (the API verifies the signature, not the SPA). */
export function decodeJwt(jwt) {
  const part = jwt.split('.')[1] ?? '';
  const json = atob(part.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(part.length / 4) * 4, '='));
  return JSON.parse(new TextDecoder().decode(Uint8Array.from(json, (c) => c.charCodeAt(0))));
}
