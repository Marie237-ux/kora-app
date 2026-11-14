import { defineConfig } from 'astro/config';
import react from '@astrojs/react';
import node from '@astrojs/node';

export default defineConfig({
  output: 'server',
  adapter: node({ mode: 'standalone' }),
  integrations: [react()],
  devToolbar: { enabled: false },
  markdown: { syntaxHighlight: false },
  security: {
    csp: {
      directives: [
        "default-src 'self'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
      ],
      styleDirective: { resources: ["'self'", "'unsafe-inline'"] },
    },
  },
  server: { 
    host: 'localhost', 
    port: 4321,
    headers: {
      'Access-Control-Allow-Origin': '*',
    },
  },
  vite: {
    server: { 
      proxy: { '/api': 'http://localhost:8000', '/sanctum': 'http://localhost:8000' },
      allowedHosts: ['.ngrok-free.app', 'ngrok-free.app', '.onrender.com', 'onrender.com'],
    },
  },
});
