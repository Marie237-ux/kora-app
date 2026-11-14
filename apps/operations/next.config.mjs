const api = process.env.API_INTERNAL_URL || 'http://localhost:8000';
export default {
  transpilePackages: ['@kora/ui', '@kora/design-tokens', '@kora/api-client'],
  async rewrites() {
    return [
      { source: '/api/:path*', destination: `${api}/api/:path*` },
      { source: '/sanctum/:path*', destination: `${api}/sanctum/:path*` },
    ];
  },
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Referrer-Policy', value: 'same-origin' },
          { key: 'Cache-Control', value: 'private, no-store' },
        ],
      },
      {
        source: '/:locale/preview/:id',
        headers: [
          { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
          { key: 'Content-Security-Policy', value: "frame-ancestors 'self'" },
        ],
      },
    ];
  },
};
