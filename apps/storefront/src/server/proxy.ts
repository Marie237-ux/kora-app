import type { APIContext } from 'astro';

export async function forwardApi({ request, url }: APIContext): Promise<Response> {
  if (!url.pathname.startsWith('/api/v1/') && url.pathname !== '/sanctum/csrf-cookie')
    return new Response(null, { status: 404 });
  const headers = new Headers();
  for (const key of [
    'accept',
    'content-type',
    'cookie',
    'x-xsrf-token',
    'x-requested-with',
    'origin',
    'referer',
    'x-kora-locale',
  ]) {
    const value = request.headers.get(key);
    if (value) headers.set(key, value);
  }
  headers.set('Accept', 'application/json');
  const target = new URL(
    url.pathname + url.search,
    import.meta.env.API_INTERNAL_URL || 'http://localhost:8000',
  );
  const options: RequestInit & { duplex?: 'half' } = {
    method: request.method,
    headers,
    redirect: 'manual',
    signal: AbortSignal.timeout(15000),
  };
  if (!['GET', 'HEAD'].includes(request.method)) {
    options.body = request.body;
    options.duplex = 'half';
  }
  try {
    const result = await fetch(target, options);
    const output = new Headers();
    for (const key of [
      'content-type',
      'cache-control',
      'x-request-id',
      'x-content-type-options',
      'retry-after',
      // Redirects of the "Continue with Google" round trip.
      'location',
    ]) {
      const value = result.headers.get(key);
      if (value) output.set(key, value);
    }
    for (const cookie of result.headers.getSetCookie()) output.append('set-cookie', cookie);
    output.set('Cache-Control', 'private, no-store');
    return new Response(result.body, { status: result.status, headers: output });
  } catch {
    return Response.json(
      {
        error: {
          code: 'API_UNAVAILABLE',
          message:
            request.headers.get('x-kora-locale') === 'en'
              ? 'The service is temporarily unavailable.'
              : 'Le service est momentanément indisponible.',
        },
      },
      { status: 503, headers: { 'Cache-Control': 'private, no-store' } },
    );
  }
}
