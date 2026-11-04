// The two modes of the demo (tabs), and the ways each one can be broken on purpose.

export const modes = {
  pkce: {
    label: 'PKCE only',
    description:
      'Authorization Code + PKCE. The tokens are plain Bearer tokens: whoever holds one can use it. The auth server ' +
      'issues them only if DPoP is not required (Settings page of the auth server, signed in as admin).',
  },
  dpop: {
    label: 'PKCE + DPoP',
    description:
      'Authorization Code + PKCE, and the tokens are bound to a key created in this browser: each request carries a ' +
      'proof signed with that key, so a stolen token is useless.',
  },
};

/**
 * Sign-in scenarios, the same in both modes. `leavesSpa`: the auth server refuses the request on its own page
 * and does not send the browser back here.
 */
export const signInScenarios = [
  {
    id: 'correct',
    label: 'Correct sign-in',
    hint: 'A code_challenge (SHA-256 of a secret verifier) goes in the address; the verifier is sent with the code.',
  },
  {
    id: 'wrong_verifier',
    label: 'Wrong code_verifier',
    hint: 'What a thief who copied the code from the address bar would do: he does not have the verifier.',
  },
  {
    id: 'no_verifier',
    label: 'No code_verifier',
    hint: 'The code is exchanged without the verifier.',
  },
  {
    id: 'reuse_code',
    label: 'Use the code twice',
    hint: 'The first exchange works, the second one is shown.',
  },
  {
    id: 'wrong_state',
    label: 'Unexpected state',
    hint: 'The answer carries a state this tab did not send, as for a sign-in started by someone else.',
  },
  {
    id: 'no_challenge',
    label: 'No code_challenge',
    hint: 'The auth server refuses at once, on its own page (JSON error). Come back with the browser\'s Back button.',
    leavesSpa: true,
  },
  {
    id: 'plain',
    label: 'code_challenge_method=plain',
    hint: 'The challenge is the verifier itself, readable in the address. After the password, the auth server refuses on its own page (JSON error).',
    leavesSpa: true,
  },
];

/** Request scenarios: how the requests to the API are sent, per mode. */
export const requestScenarios = {
  pkce: [
    { id: 'correct', label: 'Correct request', hint: 'Authorization: Bearer <token>.' },
    { id: 'no_token', label: 'No access token', hint: 'No Authorization header.' },
    { id: 'tamper', label: 'Tampered token', hint: 'One character of the token signature is changed.' },
    { id: 'as_dpop', label: 'Send it with the "DPoP" scheme', hint: 'Authorization: DPoP <Bearer token>, for a token bound to no key.' },
  ],
  dpop: [
    { id: 'correct', label: 'Correct request', hint: 'Authorization: DPoP <token>, and a new proof signed by the key of this browser.' },
    { id: 'no_token', label: 'No access token', hint: 'No Authorization header.' },
    { id: 'no_proof', label: 'No DPoP proof', hint: 'The token alone, as someone who stole it would send it.' },
    { id: 'as_bearer', label: 'Send it as "Bearer"', hint: 'Authorization: Bearer <DPoP-bound token>, without proof.' },
    { id: 'tamper', label: 'Tampered token', hint: 'One character of the token signature is changed; the proof is correct.' },
    { id: 'replay', label: 'Replayed proof', hint: 'The request is sent twice with the same proof; the second answer is shown.' },
    { id: 'other_request', label: 'Proof made for another request', hint: 'A valid proof, but signed for another address (or method).' },
    { id: 'other_key', label: 'Proof signed by another key', hint: 'What a thief holding the token, but not the key of this browser, would send.' },
  ],
};
