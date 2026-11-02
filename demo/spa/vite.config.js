import { fileURLToPath } from 'node:url';
import { defineConfig, searchForWorkspaceRoot } from 'vite';
import react from '@vitejs/plugin-react';

// The browser client is not on npm: import its source from this repository (packages/oauth-dpop-client).
const client = fileURLToPath(new URL('../../packages/oauth-dpop-client/src/oauth-dpop.ts', import.meta.url));

// Port 5173: the redirect URI registered for the demo client is http://localhost:5173/callback.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { 'oauth-dpop-client': client },
  },
  server: {
    port: 5173,
    strictPort: true,
    // Lets the dev server read the client file, outside the SPA folder.
    fs: { allow: [searchForWorkspaceRoot(process.cwd()), client] },
  },
  preview: { port: 5173, strictPort: true },
});
