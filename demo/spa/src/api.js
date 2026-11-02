// Calls the demo API. The options deliberately break the request to show how the API reacts.
import { createDpopProof } from 'oauth-dpop-client';
import { apiUrl } from './config.js';

let lastProof = null;

/**
 * @param {{method: string, path: string, body?: object}} request
 * @param {{tokens: object, keyPair: CryptoKeyPair} | null} session
 * @param {{sendToken: boolean, sendProof: boolean, asBearer: boolean, tamper: boolean, replay: boolean}} options
 */
export async function callApi(request, session, options) {
  const url = new URL(request.path, apiUrl).toString();
  const headers = {};

  if (session && options.sendToken) {
    let token = session.tokens.accessToken;
    if (options.tamper) {
      // Changes one character of the signature: the API must reject the token.
      const last = token.at(-2) === 'A' ? 'B' : 'A';
      token = `${token.slice(0, -2)}${last}${token.slice(-1)}`;
    }
    headers.Authorization = `${options.asBearer ? 'Bearer' : 'DPoP'} ${token}`;

    if (options.sendProof) {
      if (options.replay && lastProof) {
        headers.DPoP = lastProof;
      } else {
        // The proof is bound to this method and URL (without the query string) and to the access token.
        headers.DPoP = await createDpopProof(session.keyPair, request.method, url, session.tokens.accessToken);
        lastProof = headers.DPoP;
      }
    }
  }
  if (request.body) {
    headers['Content-Type'] = 'application/json';
  }

  const started = performance.now();
  let response;
  try {
    response = await fetch(url, {
      method: request.method,
      headers,
      body: request.body ? JSON.stringify(request.body) : undefined,
    });
  } catch (error) {
    return { request, url, sent: describe(headers), networkError: String(error.message ?? error) };
  }

  const text = await response.text();
  let body = text;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    // Not JSON: keep the text.
  }

  return {
    request,
    url,
    sent: describe(headers),
    status: response.status,
    ok: response.ok,
    wwwAuthenticate: response.headers.get('WWW-Authenticate'),
    body,
    durationMs: Math.round(performance.now() - started),
  };
}

/** What was sent, shortened for display. */
function describe(headers) {
  const shown = {};
  for (const [name, value] of Object.entries(headers)) {
    shown[name] = value.length > 60 ? `${value.slice(0, 40)}…${value.slice(-12)}` : value;
  }
  return shown;
}
