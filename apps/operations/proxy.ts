import { NextResponse, type NextRequest } from 'next/server';

export function proxy(request: NextRequest) {
  const nonce = Buffer.from(crypto.randomUUID()).toString('base64');
  const path = request.nextUrl.pathname;
  const locale = path.startsWith('/en') ? 'en' : 'fr';
  const development = process.env.NODE_ENV === 'development';
  const preview = /^\/(fr|en)\/preview\//.test(path);
  const policy = [
    "default-src 'self'",
    "script-src 'self' 'nonce-" +
      nonce +
      "' 'strict-dynamic'" +
      (development ? " 'unsafe-eval'" : ''),
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self'",
    "connect-src 'self'" + (development ? ' ws://localhost:3000' : ''),
    "frame-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    'frame-ancestors ' + (preview ? "'self'" : "'none'"),
  ].join('; ');
  const headers = new Headers(request.headers);
  headers.set('x-kora-locale', locale);
  headers.set('x-nonce', nonce);
  headers.set('Content-Security-Policy', policy);
  const response = NextResponse.next({ request: { headers } });
  response.headers.set('Content-Security-Policy', policy);
  return response;
}
export const config = { matcher: ['/', '/fr/:path*', '/en/:path*'] };
