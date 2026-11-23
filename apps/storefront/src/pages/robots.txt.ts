import type { APIRoute } from 'astro';

// While the site is a demo (PUBLIC_KORA_DEMO not "false") nothing is indexed; at launch, everything public is,
// except private pages (account, checkout, orders, shared links) and the API.
export const GET: APIRoute = () => {
  const origin = import.meta.env.PUBLIC_SITE_URL || 'http://localhost:4321';
  const demo = import.meta.env.PUBLIC_KORA_DEMO !== 'false';
  const body = demo
    ? 'User-agent: *\nDisallow: /\n'
    : ['User-agent: *', 'Disallow: /api/', ...['fr', 'en'].flatMap((l) => ['/account', '/commande', '/orders/', '/login', '/register', '/panier-partage/', '/inspirations/partage/', '/inspirations/invitation/', '/carte/', '/verifier-livreur'].map((x) => 'Disallow: /' + l + x)), '', 'Sitemap: ' + origin + '/sitemap.xml', ''].join('\n');
  return new Response(body, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
