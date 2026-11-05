import { useEffect, useState } from 'react';
import { abandonedLogin, decodeJwt, handleCallback, loadSession, login, logout, refresh, SignInError } from './auth.js';
import { callApi } from './api.js';
import { config, apiUrl } from './config.js';
import { explain, explainSignIn } from './explain.js';
import { requests } from './requests.js';
import { modes, requestScenarios, signInScenarios } from './scenarios.js';

const MODE_KEY = 'demo.mode';

// The chosen tab survives the redirect to the auth server. Storage may be unavailable: the tab is then not kept.
function storedMode() {
  try {
    return sessionStorage.getItem(MODE_KEY) === 'pkce' ? 'pkce' : 'dpop';
  } catch {
    return 'dpop';
  }
}

function storeMode(mode) {
  try {
    sessionStorage.setItem(MODE_KEY, mode);
  } catch {
    // Not kept: the default tab is shown after the redirect.
  }
}

const sessionMode = (session) => (session ? (session.keyPair ? 'dpop' : 'pkce') : null);

export default function App() {
  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const [mode, setMode] = useState(storedMode);
  const [signInScenario, setSignInScenario] = useState('correct');
  const [signInFailure, setSignInFailure] = useState(null);
  const [authError, setAuthError] = useState(null);
  const [scenario, setScenario] = useState('correct');
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [impersonate, setImpersonate] = useState('');
  const [custom, setCustom] = useState({ method: 'GET', path: '/api/vehicles/V-1002' });

  useEffect(() => {
    // Opened by the "Impersonate" button of the auth server's administration pages.
    const impersonateParam = new URLSearchParams(window.location.search).get('impersonate');
    if (impersonateParam && window.location.pathname !== '/callback') {
      login(impersonateParam, { dpop: storedMode() === 'dpop' });
      return;
    }

    if (window.location.pathname !== '/callback') {
      // Back from a sign-in the auth server refused on its own page (browser's Back button).
      const pending = abandonedLogin();
      const left = signInScenarios.find((s) => s.id === pending?.scenario && s.leavesSpa);
      if (left) {
        setSignInFailure(new SignInError('auth server', 'invalid_request', 'Shown by the auth server on its own page, as a JSON error.', left.id));
      }
    }

    const start = window.location.pathname === '/callback' ? handleCallback() : loadSession();
    start
      .then((loaded) => {
        setSession(loaded);
        if (loaded) {
          setMode(sessionMode(loaded));
          storeMode(sessionMode(loaded));
        }
      })
      .catch((error) => (error instanceof SignInError ? setSignInFailure(error) : setAuthError(error.message)))
      .finally(() => setLoading(false));
  }, []);

  function chooseMode(next) {
    setMode(next);
    storeMode(next);
    setScenario('correct');
    setResult(null);
  }

  async function run(request) {
    setBusy(true);
    setResult({ ...(await callApi(request, session, scenario)), scenario });
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

  if (loading) {
    return <main className="page"><p>Loading…</p></main>;
  }

  const claims = session ? decodeJwt(session.tokens.accessToken) : null;
  const otherMode = session && sessionMode(session) !== mode;
  const chosenSignIn = signInScenarios.find((s) => s.id === signInScenario);

  return (
    <main className="page">
      <header>
        <h1>Auth Server demo</h1>
        <p className="sub">
          A single-page application signing in with <code>{config.issuer}</code> and calling the demo API at{' '}
          <code>{apiUrl}</code>.
        </p>
      </header>

      <div className="tabs" role="tablist" aria-label="Mode">
        {Object.entries(modes).map(([id, { label }]) => (
          <button
            key={id}
            role="tab"
            id={`tab-${id}`}
            aria-selected={mode === id}
            aria-controls="mode-panel"
            className={mode === id ? 'tab active' : 'tab'}
            onClick={() => chooseMode(id)}
          >
            {label}
          </button>
        ))}
      </div>

      <div id="mode-panel" role="tabpanel" aria-labelledby={`tab-${mode}`}>
        <p className="help mode-description">{modes[mode].description}</p>

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
                <dt>Token type</dt>
                <dd>{session.keyPair ? 'DPoP, bound to a key of this browser' : 'Bearer, not bound: usable by whoever holds it'}</dd>
                <dt>Expires</dt><dd>{new Date(claims.exp * 1000).toLocaleTimeString()}</dd>
              </dl>
              <div className="actions">
                <button onClick={onRefresh}>Refresh tokens</button>
                <button className="secondary" onClick={() => logout()}>Sign out</button>
              </div>
            </>
          ) : (
            <>
              <fieldset>
                <legend>How to sign in</legend>
                {signInScenarios.map((s) => (
                  <label key={s.id} className="check option">
                    <input
                      type="radio"
                      name="sign-in-scenario"
                      value={s.id}
                      checked={signInScenario === s.id}
                      onChange={() => setSignInScenario(s.id)}
                    />
                    <span>{s.label}<span className="help"> — {s.hint}</span></span>
                  </label>
                ))}
              </fieldset>
              <div className="actions">
                <button onClick={() => login(undefined, { dpop: mode === 'dpop', scenario: signInScenario })}>Sign in</button>
                <span className="help">
                  {chosenSignIn.leavesSpa
                    ? 'You will stay on the auth server, which shows its error: come back with the Back button.'
                    : 'You are sent to the auth server, then back here.'}
                </span>
              </div>
              {signInFailure && <SignInFailure failure={signInFailure} />}
            </>
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
              <button
                className="secondary"
                disabled={!impersonate || Boolean(session)}
                onClick={() => login(impersonate, { dpop: mode === 'dpop' })}
              >
                Sign in as this user
              </button>
            </div>
            <p className="help">Needs ROLE_ADMIN or ROLE_SUPER_ADMIN on the auth server, with two-factor authentication set up.</p>
          </details>
        </section>

        <div className="columns">
          <section className="card">
            <h2>Requests</h2>
            {otherMode ? (
              <div className="alert notice" role="status">
                This session has {session.keyPair ? 'DPoP-bound' : 'Bearer'} tokens: it was opened in the
                “{modes[sessionMode(session)].label}” tab. Go back to that tab, or sign out and sign in again here.
              </div>
            ) : (
              <>
                <fieldset>
                  <legend>How to send them</legend>
                  {requestScenarios[mode].map((s) => (
                    <label key={s.id} className="check option">
                      <input
                        type="radio"
                        name="request-scenario"
                        value={s.id}
                        checked={scenario === s.id}
                        onChange={() => setScenario(s.id)}
                      />
                      <span>{s.label}<span className="help"> — {s.hint}</span></span>
                    </label>
                  ))}
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
                    run({ ...custom, body: ['POST', 'PUT', 'PATCH'].includes(custom.method) ? {} : undefined });
                  }}
                >
                  <select aria-label="Method" value={custom.method} onChange={(e) => setCustom({ ...custom, method: e.target.value })}>
                    {['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => <option key={m}>{m}</option>)}
                  </select>
                  <input aria-label="Path" value={custom.path} onChange={(e) => setCustom({ ...custom, path: e.target.value })} />
                  <button disabled={busy}>Send</button>
                </form>
              </>
            )}
          </section>

          <section className="card" aria-live="polite">
            <h2>Result</h2>
            {result ? <Result result={result} mode={mode} /> : <p className="help">Choose a request.</p>}
          </section>
        </div>
      </div>
    </main>
  );
}

function SignInFailure({ failure }) {
  return (
    <div className="result" data-testid="sign-in-failure">
      <div className="status bad">Sign-in refused by the {failure.source}</div>
      <dl className="reason">
        <dt>Scenario</dt><dd>{signInScenarios.find((s) => s.id === failure.scenario)?.label ?? failure.scenario}</dd>
        <dt>Error</dt><dd data-testid="sign-in-error"><code>{failure.error}</code></dd>
        {failure.description && (<><dt>Reason</dt><dd>{failure.description}</dd></>)}
        <dt>In plain words</dt><dd data-testid="sign-in-explanation">{explainSignIn(failure)}</dd>
      </dl>
    </div>
  );
}

function Result({ result, mode }) {
  const error = result.body && typeof result.body === 'object' ? result.body : null;
  const scenarioLabel = requestScenarios[mode].find((s) => s.id === result.scenario)?.label;

  return (
    <div className="result">
      <p className="request-line">
        <span className={`method ${result.request.method}`}>{result.request.method}</span> <code>{result.url}</code>
      </p>
      {scenarioLabel && <p className="help">Sent as: {scenarioLabel}</p>}
      {result.firstStatus !== undefined && (
        <p className="help" data-testid="first-status">
          First sending of the proof: {result.firstStatus}. Second sending, with the same proof:
        </p>
      )}

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

      {result.ok && <p className="help" data-testid="explanation">{explain(result)}</p>}

      {result.ok && result.body?.items ? <ItemsTable items={result.body.items} /> : null}
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

// Items may lack fields the user may not read (a facet he holds no read access to): one column per field seen.
function ItemsTable({ items }) {
  const columns = [...new Set(items.flatMap((item) => Object.keys(item)))];
  return (
    <table>
      <thead>
        <tr>{columns.map((k) => <th key={k}>{k}</th>)}</tr>
      </thead>
      <tbody>
        {items.map((item) => (
          <tr key={item.id}>{columns.map((k) => <td key={k}>{k in item ? String(item[k]) : '—'}</td>)}</tr>
        ))}
      </tbody>
    </table>
  );
}
