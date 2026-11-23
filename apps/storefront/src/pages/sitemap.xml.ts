import type { APIRoute } from 'astro';
import { api, safe } from '../lib/server';

// Public pages in both languages, with their French / English alternates: home, catalogue, shops, inspirations,
// help, about, partners, request a product, legal pages, every published shop and product.
export const GET: APIRoute = async () => {
  const origin = import.meta.env.PUBLIC_SITE_URL || 'http://localhost:4321';
  const k = api('fr');
  const [shops, first] = await Promise.all([
    safe(k.request<{ data: { slug: string }[] }>('/api/v1/shops'), { data: [] as { slug: string }[] }),
    safe(k.request<{ data: { id: string }[]; meta: { last_page: number } }>('/api/v1/catalogue?page=1'), { data: [] as { id: string }[], meta: { last_page: 1 } }),
  ]);
  const pages = await Promise.all(
    Array.from({ length: Math.min(first.value.meta.last_page, 40) - 1 }, (_, i) => safe(k.request<{ data: { id: string }[] }>('/api/v1/catalogue?page=' + (i + 2)), { data: [] as { id: string }[] })),
  );
  const offers = [...first.value.data, ...pages.flatMap((p) => p.value.data)].map((o) => o.id);
  const paths = [
    '', '/catalogue', '/shops', '/inspirations', '/help', '/about', '/partners', '/request-product', '/pro',
    '/legal/terms_of_sale', '/legal/terms_of_use', '/legal/privacy', '/legal/returns',
    ...shops.value.data.map((s) => '/shops/' + s.slug),
    ...[...new Set(offers)].map((id) => '/products/' + id),
  ];
  const url = (p: string) =>
    `<url><loc>${origin}/fr${p}</loc><xhtml:link rel="alternate" hreflang="fr-CM" href="${origin}/fr${p}"/><xhtml:link rel="alternate" hreflang="en-CM" href="${origin}/en${p}"/></url>` +
    `<url><loc>${origin}/en${p}</loc><xhtml:link rel="alternate" hreflang="fr-CM" href="${origin}/fr${p}"/><xhtml:link rel="alternate" hreflang="en-CM" href="${origin}/en${p}"/></url>`;
  const xml = `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">${paths.map(url).join('')}</urlset>`;
  return new Response(xml, { headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, max-age=3600' } });
};
