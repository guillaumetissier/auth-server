// Addresses as the browser reaches them. Set at build time, see .env.
export const config = {
  issuer: import.meta.env.VITE_ISSUER ?? 'http://localhost:8080',
  clientId: import.meta.env.VITE_CLIENT_ID ?? 'my-spa',
  redirectUri: `${window.location.origin}/callback`,
};

export const apiUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8081';
