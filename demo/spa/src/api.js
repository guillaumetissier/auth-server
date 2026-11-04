// Calls the demo API. A request scenario (scenarios.js) deliberately breaks the request to show how the API reacts.
import { createDpopProof, generateDpopKeyPair } from 'oauth-dpop-client';
import { apiUrl } from './config.js';

/**
 * @param {{method: string, path: string, body?: object}} request
 * @param {{tokens: object, keyPair: CryptoKeyPair | null} | null} session
 * @param {string} scenario id of a request scenario
 */
export async function callApi(request, session, scenario = 'correct') {
  const url = new URL(request.path, apiUrl).toString();
  const headers = await authHeaders(request, url, session, scenario);
  if (request.body) {
    headers['Content-Type'] = 'application/json';
  }

  if (scenario === 'replay' && headers.DPoP) {
    // The same proof twice: the first sending is normal, the second one is the replay.
    const first = await send(request, url, headers);
    return { ...(await send(request, url, headers)), firstStatus: first.status ?? 'no answer' };
  }
  return send(request, url, headers);
}

async function authHeaders(request, url, session, scenario) {
  if (!session || scenario === 'no_token') {
    return {};
  }
  const token = session.tokens.accessToken;
  const sent = scenario === 'tamper' ? tamper(token) : token;

  // Bearer session: the token is bound to no key, there is no proof to sign.
  if (null === session.keyPair) {
    return { Authorization: `${scenario === 'as_dpop' ? 'DPoP' : 'Bearer'} ${sent}` };
  }
  if (scenario === 'as_bearer') {
    return { Authorization: `Bearer ${sent}` };
  }

  const headers = { Authorization: `DPoP ${sent}` };
  if (scenario === 'no_proof') {
    return headers;
  }
  if (scenario === 'other_request') {
    // A valid proof, signed by the right key, but for another address.
    const otherPath = request.path === '/api/me' ? '/api/vehicles' : '/api/me';
    headers.DPoP = await createDpopProof(session.keyPair, 'GET', new URL(otherPath, apiUrl).toString(), token);
  } else if (scenario === 'other_key') {
    // A thief's key: everything is right in the proof except the key that signs it.
    headers.DPoP = await createDpopProof(await generateDpopKeyPair(), request.method, url, token);
  } else {
    // The proof is bound to this method and URL (without the query string) and to the access token.
    headers.DPoP = await createDpopProof(session.keyPair, request.method, url, token);
  }
  return headers;
}

/** Changes one character of the signature: the API must reject the token. */
function tamper(token) {
  const last = token.at(-2) === 'A' ? 'B' : 'A';
  return `${token.slice(0, -2)}${last}${token.slice(-1)}`;
}

async function send(request, url, headers) {
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
