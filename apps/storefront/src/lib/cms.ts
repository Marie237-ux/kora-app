// A CMS page for the site's simple pages (help, about, partners, request, 404…): its header photo and line from the
// first banner, and its other blocks (FAQ, editorial text, image and text) in the visitor's language.
import type { Locale } from './copy';
import { api, safe } from './server';

type T = Record<string, string> | undefined;
export type CmsBlock = {
  type: string;
  media_id?: string;
  title?: T;
  text?: T;
  link?: string;
  items?: { q: T; a: T }[];
  paragraphs?: Record<string, string[]>;
};
type CmsPage = { data: { page: { blocks: CmsBlock[] } | null; media: Record<string, string> } };

export async function cmsPage(locale: Locale, slug: string, fallback?: string) {
  const load = async (s: string) => (await safe(api(locale).request<CmsPage>('/api/v1/content/pages/' + s), { data: { page: null, media: {} } } as CmsPage)).value.data;
  const data = await load(slug);
  const tr = (v: T) => v?.[locale] ?? v?.fr ?? '';
  const blocks = data.page?.blocks ?? [];
  const banner = blocks.find((b) => b.type === 'banner' && b.media_id && data.media[b.media_id]);
  let photo = banner ? data.media[banner.media_id!] : null;
  if (!photo && fallback) {
    const other = await load(fallback);
    const b = other.page?.blocks.find((x) => x.type === 'banner' && x.media_id && other.media[x.media_id]);
    photo = b ? other.media[b.media_id!] : null;
  }
  return {
    photo,
    title: tr(banner?.title),
    text: tr(banner?.text),
    blocks: blocks.filter((b) => b !== banner),
    media: data.media,
    tr,
    paragraphs: (b: CmsBlock) => b.paragraphs?.[locale] ?? b.paragraphs?.fr ?? [],
  };
}
