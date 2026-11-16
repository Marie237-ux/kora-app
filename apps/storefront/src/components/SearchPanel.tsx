import { useEffect, useRef, useState } from 'react';
import { ApiError, createApi } from '@kora/api-client';
import { copy, money, type Locale } from '../lib/copy';
import AddToCart from './AddToCart';
import { ArrowRight, ArrowUpRight, CameraIcon, ClockIcon, CloseIcon, SearchIcon } from './icons';

type Offer = {
  id: string;
  name: string;
  image: string | null;
  category: string;
  price: { amount: number };
  compare_at_price?: number | null;
  availability?: 'available' | 'unconfirmed' | 'unavailable';
  minimum_quantity?: number;
  shop: { name: string };
};
type Aisle = { slug: string; name: string };
type PhotoResult = {
  id: string;
  status: 'completed' | 'unavailable';
  level: 'match' | 'similar' | 'none' | null;
  results: { offer: Offer; score: number; label: 'match' | 'similar' }[];
};
type Photo = { preview: string; state: 'busy' | 'done' | 'error'; result?: PhotoResult; error?: string };

const RECENT = 'kora.recent-searches';
function readRecent(): string[] {
  try {
    return JSON.parse(localStorage.getItem(RECENT) || '[]').slice(0, 6);
  } catch {
    return [];
  }
}
function remember(term: string) {
  const t = term.trim();
  if (t.length < 2) return;
  try {
    localStorage.setItem(RECENT, JSON.stringify([t, ...readRecent().filter((x) => x.toLowerCase() !== t.toLowerCase())].slice(0, 6)));
  } catch {}
}

/** The typed letters are shown in the accent colour inside each suggestion. */
function Mark({ text, term }: { text: string; term: string }) {
  const i = term ? text.toLowerCase().indexOf(term.toLowerCase()) : -1;
  if (i < 0) return <>{text}</>;
  return (
    <>
      {text.slice(0, i)}
      <mark>{text.slice(i, i + term.length)}</mark>
      {text.slice(i + term.length)}
    </>
  );
}


function Row({ o, i, locale, onPick }: { o: Offer; i: number; locale: Locale; onPick?: () => void }) {
  return (
    <li className="sx-item">
      <a className="sx-row" href={'/' + locale + '/products/' + o.id} onClick={onPick}>
        <small>{String(i + 1).padStart(2, '0')}</small>
        <span className="sx-thumb">{o.image && <img src={o.image} alt="" decoding="async" />}</span>
        <span className="sx-row-text">
          <strong>{o.name}</strong>
          <em>{o.shop.name}</em>
          <b>
            {money(o.price.amount, locale)}
            {o.compare_at_price && o.compare_at_price > o.price.amount ? <s>{money(o.compare_at_price, locale)}</s> : null}
          </b>
        </span>
      </a>
      <AddToCart locale={locale} variant="cart" offerId={o.id} name={o.name} minimum={o.minimum_quantity ?? 1} disabled={o.availability === 'unavailable'} />
    </li>
  );
}

/**
 * Search screen (full sheet): a soft field with Cancel; before typing, recent searches, aisles and a few products
 * to start from; while typing, text suggestions with the typed letters highlighted and product cards with the real
 * price. Only the published catalogue is queried — no AI, no invented results.
 */
export default function SearchPanel({ locale, open, onClose, query = '', aisles = [] }: { locale: Locale; open: boolean; onClose: () => void; query?: string; aisles?: Aisle[] }) {
  const c = copy(locale);
  const input = useRef<HTMLInputElement>(null);
  const [q, setQ] = useState(query);
  const [items, setItems] = useState<Offer[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [recent, setRecent] = useState<string[]>([]);
  const [picks, setPicks] = useState<Offer[]>([]);
  const [shops, setShops] = useState<{ name: string; href: string; image: string | null }[]>([]);
  const [photo, setPhoto] = useState<Photo | null>(null);
  const file = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) return;
    setRecent(readRecent());
    window.setTimeout(() => input.current?.focus(), 80);
    if (picks.length === 0) {
      const get = (page: number) =>
        fetch('/api/v1/catalogue?page=' + page, { headers: { Accept: 'application/json', 'X-Kora-Locale': locale } })
          .then((r) => (r.ok ? r.json() : { data: [] }))
          .then((d) => d.data as Offer[])
          .catch(() => [] as Offer[]);
      Promise.all([get(1), get(2)]).then(([a, b]) => {
        const all = [...a, ...b].filter((o) => o.image);
        // one real product photo per aisle, and a varied first selection (one product per aisle)
        const map: Record<string, Offer> = {};
        for (const o of all) if (!map[o.category]) map[o.category] = o;
        setPicks(Object.values(map).slice(0, 5));
      });
      fetch('/api/v1/content/pages/home', { headers: { Accept: 'application/json', 'X-Kora-Locale': locale } })
        .then((r) => (r.ok ? r.json() : null))
        .then((d) => {
          const blocks = (d?.data?.page?.blocks ?? []) as { type: string; link?: string; media_id?: string; title?: Record<string, string> }[];
          const media = (d?.data?.media ?? {}) as Record<string, string>;
          setShops(
            blocks
              .filter((b) => b.type === 'image_text' && (b.link ?? '').includes('/shops/'))
              .map((b) => ({ name: b.title?.[locale] || b.title?.fr || '', href: '/' + locale + b.link, image: b.media_id ? media[b.media_id] ?? null : null })),
          );
        })
        .catch(() => {});
    }
  }, [open]);
  useEffect(() => {
    const term = q.trim();
    if (!open || term.length < 2) {
      setItems(null);
      return;
    }
    const ctrl = new AbortController();
    const t = window.setTimeout(async () => {
      setBusy(true);
      try {
        const r = await fetch('/api/v1/catalogue/suggestions?q=' + encodeURIComponent(term) + '&locale=' + locale, {
          headers: { Accept: 'application/json', 'X-Kora-Locale': locale },
          signal: ctrl.signal,
        });
        setItems(r.ok ? ((await r.json()).data as Offer[]) : []);
      } catch {
        if (!ctrl.signal.aborted) setItems([]);
      } finally {
        if (!ctrl.signal.aborted) setBusy(false);
      }
    }, 180);
    return () => {
      ctrl.abort();
      window.clearTimeout(t);
    };
  }, [q, open, locale]);

  async function searchPhoto(f: File) {
    if (photo?.preview) URL.revokeObjectURL(photo.preview);
    const preview = URL.createObjectURL(f);
    setPhoto({ preview, state: 'busy' });
    const body = new FormData();
    body.append('file', f);
    if (q.trim()) body.append('q', q.trim().slice(0, 150));
    try {
      const r = await createApi('', locale).request<{ data: PhotoResult }>('/api/v1/search/photo', { method: 'POST', body });
      setPhoto({ preview, state: 'done', result: r.data });
    } catch (e) {
      const status = e instanceof ApiError ? e.status : 0;
      setPhoto({ preview, state: 'error', error: status === 429 ? c.photoTooMany : status === 422 ? c.photoInvalid : c.photoFailed });
    }
  }
  function clearPhoto() {
    if (photo?.preview) URL.revokeObjectURL(photo.preview);
    setPhoto(null);
    if (file.current) file.current.value = '';
  }

  if (!open) return null;
  const term = q.trim();
  const base = '/' + locale + '/catalogue';
  const results = base + '?q=' + encodeURIComponent(term);
  const keep = () => remember(term);
  return (
    <div className="sx" id="site-search" role="dialog" aria-modal="true" aria-label={c.search}>
      <div className="sx-inner">
        <div className="sx-head">
        <div className="sx-top">
          <h2 className="sx-title">{c.searchOnKora}</h2>
          <button type="button" className="sx-cancel" onClick={onClose}>{c.cancel}</button>
        </div>
        <form className="sx-bar" action={base} role="search" onSubmit={keep}>
          <label className="sx-field">
            <SearchIcon />
            <span className="sr-only">{c.search}</span>
            <input
              ref={input}
              type="search"
              name="q"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={c.searchPlaceholder}
              autoComplete="off"
              enterKeyHint="search"
            />
            {q && (
              <button
                type="button"
                className="sx-clear"
                onClick={() => {
                  setQ('');
                  input.current?.focus();
                }}
                aria-label={c.clear}
              >
                <CloseIcon />
              </button>
            )}
            <button type="button" className="sx-camera" onClick={() => file.current?.click()} aria-label={c.photoButton} title={c.photoButton}>
              <CameraIcon />
            </button>
            <input
              ref={file}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              hidden
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) void searchPhoto(f);
              }}
            />
          </label>
        </form>
        </div>

        {photo ? (
          <div className="sx-body" aria-live="polite" aria-busy={photo.state === 'busy'}>
            <div className="sx-photo">
              <img src={photo.preview} alt={c.photoYours} />
              <div>
                <strong>{c.photoYours}</strong>
                <span>{photo.state === 'busy' ? c.photoAnalysing : photo.result?.status === 'completed' ? (photo.result.level === 'match' ? c.photoMatchTitle : photo.result.level === 'similar' ? c.photoSimilarTitle : c.photoNoneTitle) : ''}</span>
              </div>
              <button type="button" className="sx-clear" onClick={clearPhoto} aria-label={c.photoRemove}><CloseIcon /></button>
              {photo.state === 'busy' && <i className="sx-scan" aria-hidden="true" />}
            </div>
            {photo.state === 'error' && <p className="notice error">{photo.error}</p>}
            {photo.state === 'done' && photo.result?.status === 'unavailable' && (
              <div className="sx-empty">
                <span className="sx-empty-icon"><CameraIcon /></span>
                <h3>{c.photoOffTitle}</h3>
                <p>{c.photoOffText}</p>
                <a className="btn btn-accent btn-small" href={'/' + locale + '/request-product'}>{c.requestAction} <ArrowRight /></a>
              </div>
            )}
            {photo.state === 'done' && photo.result?.status === 'completed' && (photo.result.results.length > 0 ? (
              <section>
                <header><h3>{photo.result.level === 'match' ? c.photoMatchTitle : c.photoSimilarTitle}</h3></header>
                <ol className="sx-list">
                  {photo.result.results.map((r, i) => <Row key={r.offer.id} o={r.offer} i={i} locale={locale} />)}
                </ol>
              </section>
            ) : (
              <div className="sx-empty">
                <span className="sx-empty-icon"><SearchIcon /></span>
                <h3>{c.photoNoneTitle}</h3>
                <p>{c.photoNoneText}</p>
                <a className="btn btn-accent btn-small" href={'/' + locale + '/request-product'}>{c.requestAction} <ArrowRight /></a>
              </div>
            ))}
          </div>
        ) : items === null ? (
          <div className="sx-body sx-home">
            <div className="sx-col">
            {recent.length > 0 && (
              <section>
                <header>
                  <h3>{c.recentSearches}</h3>
                  <button
                    type="button"
                    onClick={() => {
                      try {
                        localStorage.removeItem(RECENT);
                      } catch {}
                      setRecent([]);
                    }}
                  >
                    {c.clearAll}
                  </button>
                </header>
                <div className="sx-chips">
                  {recent.map((r) => (
                    <button type="button" key={r} onClick={() => setQ(r)}><ClockIcon /> {r}</button>
                  ))}
                </div>
              </section>
            )}
            {aisles.length > 0 && (
              <section>
                <header><h3>{c.categories}</h3></header>
                <div className="sx-chips">
                  {aisles.map((a) => (
                    <a key={a.slug} href={base + '?category=' + a.slug}>{a.name}</a>
                  ))}
                </div>
              </section>
            )}
            {shops.length > 0 && (
              <section>
                <header>
                  <h3>{c.shops}</h3>
                  <a href={'/' + locale + '/shops'}>{c.seeAll}</a>
                </header>
                <div className="sx-shops">
                  {shops.map((sh) => (
                    <a key={sh.href} href={sh.href}>
                      <span>{sh.image ? <img src={sh.image} alt="" decoding="async" /> : sh.name.slice(0, 1)}</span>
                      {sh.name}
                    </a>
                  ))}
                </div>
              </section>
            )}
            <div className="sx-ask">
              <span>
                <b>{c.askTitle}</b>
                <small>{c.askText}</small>
              </span>
              <a href={'/' + locale + '/request-product'}>
                {c.requestAction}
                <ArrowRight />
              </a>
            </div>
            </div>
            {picks.length > 0 && (
              <section className="sx-col sx-start">
                <header><h3>{c.youMayLike}</h3></header>
                <ol className="sx-list">
                  {picks.map((o, i) => <Row key={o.id} o={o} i={i} locale={locale} />)}
                </ol>
              </section>
            )}
          </div>
        ) : (
          <div className="sx-body" aria-live="polite" aria-busy={busy}>
            {items.length === 0 ? (
              <div className="sx-empty">
                <span className="sx-empty-icon"><SearchIcon /></span>
                <h3>{c.noResultsFor} « {term} »</h3>
                <p>{c.noResultsText}</p>
                <a className="btn btn-dark btn-small" href={'/' + locale + '/request-product'}>{c.requestAction} <ArrowRight /></a>
              </div>
            ) : (
              <>
                <ul className="sx-suggest">
                  {items.slice(0, 5).map((o) => (
                    <li key={o.id}>
                      <a href={'/' + locale + '/products/' + o.id} onClick={keep}>
                        <SearchIcon />
                        <span><Mark text={o.name} term={term} /></span>
                        <ArrowUpRight />
                      </a>
                    </li>
                  ))}
                </ul>
                <section>
                  <header>
                    <h3>{c.products}</h3>
                    <a href={results} onClick={keep}>{c.seeAll} <ArrowRight /></a>
                  </header>
                  <ol className="sx-list">
                    {items.slice(0, 6).map((o, i) => <Row key={o.id} o={o} i={i} locale={locale} onPick={keep} />)}
                  </ol>
                </section>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
