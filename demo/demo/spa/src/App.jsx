import { useEffect, useState } from 'react';
import { decodeJwt, handleCallback, loadSession, login, logout, refresh } from './auth.js';
import { callApi } from './api.js';
import { config, apiUrl } from './config.js';
import { explain } from './explain.js';
import { requests } from './requests.js';

const defaultOptions = { sendToken: true, sendProof: true, asBearer: false, tamper: false, replay: false };

const optionLabels = {
  sendToken: 'Send the access token',
  sendProof: 'Send a DPoP proof',
  asBearer: 'Send the token as "Bearer" instead of "DPoP"',
  tamper: 'Tamper with the token (change its signature)',
  replay: 'Replay the previous proof instead of a new one',
};

export default function App() {
  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const [authError, setAuthError] = useState(null);
  const [options, setOptions] = useState(defaultOptions);
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [impersonate, setImpersonate] = useState('');
  const [custom, setCustom] = useState({ method: 'GET', path: '/api/vehicles/V-1002' });

  useEffect(() => {
    // Opened by the "Impersonate" button of the auth server's administration pages.
    const impersonateParam = new URLSearchParams(window.location.search).get('impersonate');
    if (impersonateParam && window.location.pathname !== '/callback') {
      login(impersonateParam);
      return;
    }

    const start = window.location.pathname === '/callback' ? handleCallback() : loadSession();
    start
      .then(setSession)
      .catch((error) => setAuthError(error.message))
      .finally(() => setLoading(false));
  }, []);

  async function run(request) {
    setBusy(true);
    setResult(await callApi(request, session, options));
    setBusy(false);
  }

  async function onRefresh() {
    try {
      setSession(await refresh(session));
      setAuthError(null);
    } catch (error) {
      setAuthError(`Refresh failed: ${error.message}. Sign in again.`);
    }
  }

  async function onLogout() {
    await logout(); // leaves the page: the auth server closes its session and sends the browser back
  }

  if (loading) {
    return <main className="page"><p>Loading…</p></main>;
  }

  const claims = session ? decodeJwt(session.tokens.accessToken) : null;

  return (
    <main className="page">
      <header>
        <h1>Auth Server demo</h1>
        <p className="sub">
          A single-page application signing in with <code>{config.issuer}</code> and calling the demo API at{' '}
          <code>{apiUrl}</code>.
        </p>
      </header>

      {authError && <div className="alert error" role="alert">{authError}</div>}

      <section className="card">
        <h2>Session</h2>
        {session ? (
          <>
            <dl className="claims">
              <dt>User (sub)</dt><dd>{claims.sub}</dd>
              <dt>Application</dt><dd>{Array.isArray(claims.aud) ? claims.aud.join(', ') : claims.aud}</dd>
              <dt>Scopes</dt><dd>{(claims.scopes ?? []).join(' ') || '—'}</dd>
              <dt>Domain</dt><dd>{claims.dom ?? 'none'}</dd>
              <dt>Rights</dt>
              <dd>
                {(claims.acl ?? []).length === 0 ? 'none' : (
                  <ul>{claims.acl.map(([domain, subdomains, expression], i) => (
                    <li key={i}><code>{expression}</code> on {domain}{subdomains ? ' and subdomains' : ''}</li>
                  ))}</ul>
                )}
              </dd>
              {claims.act && (<><dt>Impersonated by</dt><dd>{claims.act.sub} ({claims.imp_mode})</dd></>)}
              <dt>Expires</dt><dd>{new Date(claims.exp * 1000).toLocaleTimeString()}</dd>
            </dl>
            <div className="actions">
              <button onClick={onRefresh}>Refresh tokens</button>
              <button className="secondary" onClick={onLogout}>Sign out</button>
            </div>
          </>
        ) : (
          <div className="actions">
            <button onClick={() => login()}>Sign in</button>
            <span className="help">You are sent to the auth server, then back here.</span>
          </div>
        )}
        <details>
          <summary>Impersonate a user (administrators)</summary>
          <div className="actions">
            <input
              aria-label="External id of the user"
              placeholder="External id, e.g. 101"
              value={impersonate}
              onChange={(e) => setImpersonate(e.target.value.trim())}
            />
            <button className="secondary" disabled={!impersonate} onClick={() => login(impersonate)}>
              Sign in as this user
            </button>
          </div>
          <p className="help">Needs ROLE_ADMIN or ROLE_SUPER_ADMIN on the auth server, with two-factor authentication set up.</p>
        </details>
      </section>

      <div className="columns">
        <section className="card">
          <h2>Requests</h2>
          <fieldset>
            <legend>How to send them</legend>
            {Object.entries(optionLabels).map(([name, label]) => (
              <label key={name} className="check">
                <input
                  type="checkbox"
                  checked={options[name]}
                  onChange={(e) => setOptions({ ...options, [name]: e.target.checked })}
                />
                {label}
              </label>
            ))}
            <button className="link" onClick={() => setOptions(defaultOptions)}>Reset to a correct request</button>
          </fieldset>

          <ul className="requests">
            {requests.map((request) => (
              <li key={request.id}>
                <button disabled={busy} onClick={() => run(request)}>
                  <span className={`method ${request.method}`}>{request.method}</span> {request.label}
                </button>
                <span className="help"><code>{request.path}</code> {request.hint}</span>
              </li>
            ))}
          </ul>

          <form
            className="custom"
            onSubmit={(e) => {
              e.preventDefault();
              run({ ...custom, body: ['POST', 'PUT'].includes(custom.method) ? {} : undefined });
            }}
          >
            <select aria-label="Method" value={custom.method} onChange={(e) => setCustom({ ...custom, method: e.target.value })}>
              {['GET', 'POST', 'PUT', 'DELETE'].map((m) => <option key={m}>{m}</option>)}
            </select>
            <input aria-label="Path" value={custom.path} onChange={(e) => setCustom({ ...custom, path: e.target.value })} />
            <button disabled={busy}>Send</button>
          </form>
        </section>

        <section className="card" aria-live="polite">
          <h2>Result</h2>
          {result ? <Result result={result} /> : <p className="help">Choose a request.</p>}
        </section>
      </div>
    </main>
  );
}

function Result({ result }) {
  const error = result.body && typeof result.body === 'object' ? result.body : null;

  return (
    <div className="result">
      <p className="request-line">
        <span className={`method ${result.request.method}`}>{result.request.method}</span> <code>{result.url}</code>
      </p>

      {result.networkError ? (
        <div className="status bad" data-testid="status">No answer</div>
      ) : (
        <div className={`status ${result.ok ? 'good' : 'bad'}`} data-testid="status">
          {result.status} {result.ok ? 'Allowed' : result.status === 401 ? 'Not authenticated' : result.status === 403 ? 'Refused' : 'Error'}
          <span className="duration">{result.durationMs} ms</span>
        </div>
      )}

      {!result.ok && (
        <dl className="reason">
          {error?.error && (<><dt>Error</dt><dd data-testid="error"><code>{error.error}</code></dd></>)}
          {error?.error_description && (<><dt>Reason</dt><dd data-testid="reason">{error.error_description}</dd></>)}
          {result.networkError && (<><dt>Reason</dt><dd>{result.networkError}</dd></>)}
          <dt>In plain words</dt><dd data-testid="explanation">{explain(result)}</dd>
          {result.wwwAuthenticate && (<><dt>WWW-Authenticate</dt><dd><code>{result.wwwAuthenticate}</code></dd></>)}
        </dl>
      )}

      {result.ok && result.body?.items ? (
        <table>
          <thead>
            <tr>{Object.keys(result.body.items[0]).map((k) => <th key={k}>{k}</th>)}</tr>
          </thead>
          <tbody>
            {result.body.items.map((item) => (
              <tr key={item.id}>{Object.values(item).map((v, i) => <td key={i}>{String(v)}</td>)}</tr>
            ))}
          </tbody>
        </table>
      ) : null}
      {result.ok && result.body?.items && result.body.hidden > 0 && (
        <p className="help">{result.body.hidden} more not shown: you may not read them.</p>
      )}

      <details open={result.ok && !result.body?.items}>
        <summary>Response body</summary>
        <pre>{typeof result.body === 'string' ? result.body : JSON.stringify(result.body, null, 2)}</pre>
      </details>
      <details>
        <summary>Headers sent</summary>
        <pre>{Object.keys(result.sent).length ? JSON.stringify(result.sent, null, 2) : 'none'}</pre>
      </details>
    </div>
  );
}
