'use client';
import { useEffect, useState } from 'react';
import { ApiError, createApi, type Locale, type User } from '@kora/api-client';
import { MediaPicker } from './MediaLibrary';

/**
 * Storefront home sections (CMS page "home"): aisle tiles, shop tiles, the product selection and the banner.
 * Each image is a media-library asset; texts are FR/EN; links are internal paths. Save creates a draft version,
 * Publish puts it online. The hero carousel keeps its own editor (Carrousel).
 */
type T = { fr: string; en: string };
type Block =
  | { type: 'image_text'; media_id: string; title: T; text: T; link: string }
  | { type: 'banner'; media_id: string; text: T; link: string; title?: T; cta?: T }
  | { type: 'product_selection'; title: T; product_ids: string[] }
  | { type: string; [key: string]: unknown };
type Offer = { id: string; product_id: string; name: string; image: string | null; shop: { name: string } };

const words = {
  fr: {
    title: 'Pages du site',
    intro: 'Sections sous le carrousel. Un lien vers /catalogue?category=… place la tuile dans « Explorer par rayon », un lien vers /shops/… dans « Les commerçants ». Les images viennent de la médiathèque (images publiques).',
    aisle: 'Tuile (rayon ou boutique)',
    banner: 'Bannière « À la une »',
    selection: 'Sélection de produits',
    other: 'Autre bloc (conservé tel quel)',
    titleFr: 'Titre (FR)', titleEn: 'Titre (EN)', textFr: 'Texte (FR)', textEn: 'Texte (EN)',
    bannerHint: 'La première phrase devient le titre, la suite le texte. Prix et boutique viennent du produit lié.',
    kickerFr: 'Étiquette (FR, ex. À la une)', kickerEn: 'Étiquette (EN, ex. Featured)', ctaFr: 'Bouton (FR, ex. Découvrir)', ctaEn: 'Bouton (EN, ex. Discover)',
    link: 'Lien (ex. /catalogue?category=audio, /shops/akwa-style, /products/…)',
    image: 'Image', products: 'Produits (24 au plus, dans l’ordre coché)',
    add: 'Ajouter', remove: 'Retirer', up: 'Monter', down: 'Descendre',
    save: 'Enregistrer le brouillon', publish: 'Publier', saved: 'Brouillon enregistré.', published: 'Page publiée.',
    live: 'Version en ligne', draft: 'Brouillon', none: 'Aucune page publiée : l’accueil se remplit avec le catalogue.',
    error: 'L’enregistrement a échoué.', loading: 'Chargement…', open: 'Voir le site',
  },
  en: {
    title: 'Site pages',
    intro: 'Sections under the carousel. A link to /catalogue?category=… puts the tile in “Shop by aisle”, a link to /shops/… in “The merchants”. Images come from the media library (public images).',
    aisle: 'Tile (aisle or shop)',
    banner: '“Featured” banner',
    selection: 'Product selection',
    other: 'Other block (kept as is)',
    titleFr: 'Title (FR)', titleEn: 'Title (EN)', textFr: 'Text (FR)', textEn: 'Text (EN)',
    bannerHint: 'The first sentence becomes the title, the rest the text. Price and shop come from the linked product.',
    kickerFr: 'Label (FR, e.g. À la une)', kickerEn: 'Label (EN, e.g. Featured)', ctaFr: 'Button (FR, e.g. Découvrir)', ctaEn: 'Button (EN, e.g. Discover)',
    link: 'Link (e.g. /catalogue?category=audio, /shops/akwa-style, /products/…)',
    image: 'Image', products: 'Products (up to 24, in ticked order)',
    add: 'Add', remove: 'Remove', up: 'Move up', down: 'Move down',
    save: 'Save draft', publish: 'Publish', saved: 'Draft saved.', published: 'Page published.',
    live: 'Live version', draft: 'Draft', none: 'No page published: the home is filled from the catalogue.',
    error: 'Saving failed.', loading: 'Loading…', open: 'Open the site',
  },
};
const media = (id?: string) => (id ? '/api/v1/media/' + id + '/public' : null);
const mediaId = (url: string | null) => url?.match(/media\/([0-9a-f-]{36})/)?.[1] ?? '';
const empty: T = { fr: '', en: '' };

export function HomeEditor({ locale, user, onDirtyChange }: { locale: Locale; user: User; onDirtyChange?: (d: boolean) => void }) {
  const w = words[locale];
  const api = createApi('', locale);
  const [pageSlug, setPageSlug] = useState<'home' | 'catalogue' | 'product' | 'shops'>('home');
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [offers, setOffers] = useState<Offer[]>([]);
  const [live, setLive] = useState<number | null>(null);
  const [draftId, setDraftId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const canWrite = user.permissions.includes('content.write');
  const canPublish = user.permissions.includes('content.publish');

  useEffect(() => {
    (async () => {
      try {
        const docs = await api.request<{ data: { id: string; slug: string; live_number: number | null }[] }>('/api/v1/operations/cms/documents?kind=page');
        const home = docs.data.find((d) => d.slug === pageSlug);
        setBlocks([]);
        setLive(null);
        setDraftId(null);
        if (home) {
          setLive(home.live_number);
          const versions = await api.request<{ data: { id: string; number: number; status: string; content: { blocks: Block[] } }[] }>('/api/v1/operations/cms/documents/' + home.id + '/versions');
          const latest = versions.data[0];
          if (latest) {
            setBlocks(latest.content.blocks ?? []);
            if (latest.status === 'draft') setDraftId(latest.id);
          }
        }
        const pages = await Promise.all([1, 2, 3].map((p) => api.request<{ data: Offer[] }>('/api/v1/catalogue?page=' + p).catch(() => ({ data: [] as Offer[] }))));
        setOffers(pages.flatMap((p) => p.data));
      } catch {
        setError(w.error);
      } finally {
        setLoading(false);
      }
    })();
  }, [locale, pageSlug]);

  function change(i: number, patch: Record<string, unknown>) {
    setBlocks((all) => all.map((b, k) => (k === i ? ({ ...b, ...patch } as Block) : b)));
    setDraftId(null);
    onDirtyChange?.(true);
  }
  function move(i: number, d: number) {
    setBlocks((all) => {
      const next = [...all];
      const [b] = next.splice(i, 1);
      next.splice(Math.max(0, Math.min(next.length, i + d)), 0, b);
      return next;
    });
    onDirtyChange?.(true);
  }
  function add(type: 'image_text' | 'banner' | 'product_selection') {
    const b: Block =
      type === 'image_text'
        ? { type, media_id: '', title: { ...empty }, text: { ...empty }, link: '/catalogue' }
        : type === 'banner'
          ? { type, media_id: '', text: { ...empty }, link: '/catalogue' }
          : { type, title: { ...empty }, product_ids: [] };
    setBlocks((all) => [...all, b]);
    onDirtyChange?.(true);
  }
  async function save(publish: boolean) {
    setBusy(true);
    setError('');
    setStatus('');
    setFields({});
    try {
      const draft = await api.request<{ data: { id: string } }>('/api/v1/operations/cms/page/' + pageSlug + '/drafts', {
        method: 'POST',
        headers: { 'Idempotency-Key': crypto.randomUUID() },
        body: JSON.stringify({ title: pageSlug === 'home' ? { fr: 'Accueil', en: 'Home' } : pageSlug === 'product' ? { fr: 'Fiche produit', en: 'Product page' } : pageSlug === 'shops' ? { fr: 'Nos boutiques', en: 'Our shops' } : { fr: 'Catalogue', en: 'Catalogue' }, content: { blocks } }),
      });
      setDraftId(draft.data.id);
      if (publish) {
        await api.request('/api/v1/operations/cms/versions/' + draft.data.id + '/publish', {
          method: 'POST',
          headers: { 'Idempotency-Key': crypto.randomUUID() },
          body: JSON.stringify({}),
        });
        setStatus(w.published);
        setDraftId(null);
      } else setStatus(w.saved);
      onDirtyChange?.(false);
    } catch (e) {
      setError(e instanceof ApiError && e.message ? e.message : w.error);
      if (e instanceof ApiError) setFields(e.fields);
    } finally {
      setBusy(false);
    }
  }
  const fieldError = (path: string) =>
    Object.entries(fields)
      .filter(([k]) => k.startsWith(path))
      .map(([, v]) => v.join(' '))
      .join(' ');
  const tr = (i: number, key: 'title' | 'text', b: Block) => {
    const v = (b as { [k: string]: T })[key] ?? empty;
    return (
      <div className="row" style={{ alignItems: 'flex-start' }}>
        {(['fr', 'en'] as const).map((l) => (
          <label className="field" key={l} style={{ flex: 1 }}>
            {key === 'title' ? (l === 'fr' ? w.titleFr : w.titleEn) : l === 'fr' ? w.textFr : w.textEn}
            {key === 'text' ? (
              <textarea rows={2} value={v[l]} disabled={!canWrite} onChange={(e) => change(i, { [key]: { ...v, [l]: e.target.value } })} />
            ) : (
              <input value={v[l]} disabled={!canWrite} onChange={(e) => change(i, { [key]: { ...v, [l]: e.target.value } })} />
            )}
          </label>
        ))}
      </div>
    );
  };

  if (loading) return <p role="status">{w.loading}</p>;
  return (
    <div className="stack">
      <div className="row" style={{ justifyContent: 'space-between', alignItems: 'end' }}>
        <div>
          <h1 style={{ fontSize: '2.7rem' }}>{w.title}</h1>
          <label className="field" style={{ maxWidth: 320 }}>
            {locale === 'fr' ? 'Page' : 'Page'}
            <select value={pageSlug} onChange={(e) => setPageSlug(e.target.value as 'home' | 'catalogue' | 'product' | 'shops')}>
              <option value="home">{locale === 'fr' ? 'Accueil' : 'Home'}</option>
              <option value="catalogue">{locale === 'fr' ? 'Catalogue (image d’en-tête : bannière)' : 'Catalogue (header image: banner)'}</option>
              <option value="shops">{locale === 'fr' ? 'Nos boutiques (image d’en-tête : bannière)' : 'Our shops (header image: banner)'}</option>
              <option value="product">{locale === 'fr' ? 'Fiche produit (image d’en-tête : bannière)' : 'Product page (header image: banner)'}</option>
            </select>
          </label>
          <p>{w.intro}</p>
          <p>
            <strong>{live ? w.live + ' : ' + live : w.none}</strong>
            {draftId ? ' · ' + w.draft : ''}
          </p>
        </div>
        <a className="button-link" href={(process.env.NEXT_PUBLIC_STOREFRONT_URL ?? 'http://localhost:4321') + '/' + locale} target="_blank" rel="noreferrer">
          {w.open}
        </a>
      </div>
      {error && <p className="error" role="alert">{error}</p>}
      {status && <p className="notice" role="status">{status}</p>}
      {blocks.map((b, i) => (
        <fieldset className="stack" key={i} style={{ border: '1px solid #ddded7', borderRadius: 16, padding: 18 }}>
          <legend>
            <strong>
              {i + 1}. {b.type === 'image_text' ? w.aisle : b.type === 'banner' ? w.banner : b.type === 'product_selection' ? w.selection : w.other}
            </strong>
          </legend>
          {(b.type === 'image_text' || b.type === 'banner') && (
            <div className="field">
              <span>{w.image}</span>
              <MediaPicker locale={locale} user={user} value={media((b as { media_id: string }).media_id)} onChange={(url) => change(i, { media_id: mediaId(url) })} />
            </div>
          )}
          {b.type === 'image_text' && tr(i, 'title', b)}
          {(b.type === 'image_text' || b.type === 'banner') && tr(i, 'text', b)}
          {b.type === 'banner' && <small>{w.bannerHint}</small>}
          {b.type === 'banner' &&
            (['title', 'cta'] as const).map((key) => {
              const v = ((b as { [k: string]: T | undefined })[key] ?? { fr: '', en: '' }) as T;
              return (
                <div className="row" key={key} style={{ alignItems: 'flex-start' }}>
                  {(['fr', 'en'] as const).map((l) => (
                    <label className="field" key={l} style={{ flex: 1 }}>
                      {key === 'title' ? (l === 'fr' ? w.kickerFr : w.kickerEn) : l === 'fr' ? w.ctaFr : w.ctaEn}
                      <input value={v[l]} maxLength={key === 'title' ? 40 : 30} disabled={!canWrite} onChange={(e) => change(i, { [key]: { ...v, [l]: e.target.value } })} />
                    </label>
                  ))}
                </div>
              );
            })}
          {(b.type === 'image_text' || b.type === 'banner') && (
            <label className="field">
              {w.link}
              <input value={(b as { link: string }).link ?? ''} disabled={!canWrite} onChange={(e) => change(i, { link: e.target.value })} />
            </label>
          )}
          {b.type === 'product_selection' && (
            <>
              {tr(i, 'title', b)}
              <div className="field">
                <span>{w.products}</span>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 8 }}>
                  {offers.map((o) => {
                    const ids = (b as { product_ids: string[] }).product_ids;
                    const on = ids.includes(o.product_id);
                    return (
                      <label key={o.id} className="row" style={{ gap: 8, alignItems: 'center', padding: 6, border: '1px solid #ddded7', borderRadius: 10 }}>
                        <input
                          type="checkbox"
                          checked={on}
                          disabled={!canWrite || (!on && ids.length >= 24)}
                          onChange={() => change(i, { product_ids: on ? ids.filter((x) => x !== o.product_id) : [...ids, o.product_id] })}
                        />
                        {o.image && <img src={o.image} alt="" width={36} height={36} style={{ objectFit: 'contain' }} />}
                        <span>
                          {o.name}
                          <br />
                          <small>{o.shop.name}</small>
                        </span>
                      </label>
                    );
                  })}
                </div>
              </div>
            </>
          )}
          {fieldError('content.blocks.' + i) && <p className="error">{fieldError('content.blocks.' + i)}</p>}
          {canWrite && (
            <div className="row">
              <button type="button" onClick={() => move(i, -1)} disabled={i === 0}>{w.up}</button>
              <button type="button" onClick={() => move(i, 1)} disabled={i === blocks.length - 1}>{w.down}</button>
              <button type="button" onClick={() => { setBlocks((all) => all.filter((_, k) => k !== i)); onDirtyChange?.(true); }}>{w.remove}</button>
            </div>
          )}
        </fieldset>
      ))}
      {canWrite && (
        <div className="row">
          <button type="button" onClick={() => add('image_text')}>{w.add} : {w.aisle}</button>
          <button type="button" onClick={() => add('product_selection')}>{w.add} : {w.selection}</button>
          <button type="button" onClick={() => add('banner')}>{w.add} : {w.banner}</button>
        </div>
      )}
      {canWrite && (
        <div className="row">
          <button type="button" onClick={() => void save(false)} disabled={busy}>{w.save}</button>
          {canPublish && (
            <button type="button" className="primary" onClick={() => void save(true)} disabled={busy}>
              {w.publish}
            </button>
          )}
        </div>
      )}
    </div>
  );
}
