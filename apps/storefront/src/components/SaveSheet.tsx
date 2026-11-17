import { useEffect, useRef, useState, type CSSProperties, type SyntheticEvent } from 'react';
import { createApi } from '@kora/api-client';
import { copy, type Locale } from '../lib/copy';
import { CheckIcon, CloseIcon, PlusIcon } from './icons';

// One sheet for every heart on the page (product cards and the product page). Hearts are plain buttons carrying
// data-save-offer, so cards replaced in place by the catalogue filters keep working. Pressing one opens two choices:
// add to the member's favourites, or pin to one of their inspiration boards (spec §11). Visitors who are not signed
// in are invited to sign in; nothing is saved in their name.

type Board = { id: string; title: string; version: number; pin_count: number; role: string | null; is_favorites?: boolean };
type Target = { offerId: string; name: string; rect: DOMRect; shop?: boolean };
type Done = Record<string, 'busy' | 'added' | 'already' | 'error'>;

const HeartIcon = ({ filled = false }: { filled?: boolean }) => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth={1.9} strokeLinejoin="round">
    <path d="M12 20.2s-7.4-4.5-9.1-9.2C1.7 7.6 4 4.4 7.3 4.4c2 0 3.6 1.1 4.7 2.8 1.1-1.7 2.7-2.8 4.7-2.8 3.3 0 5.6 3.2 4.4 6.6-1.7 4.7-9.1 9.2-9.1 9.2Z" />
  </svg>
);
const BoardIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinejoin="round">
    <rect x="3.5" y="3.5" width="7.5" height="10" rx="2" /><rect x="13" y="3.5" width="7.5" height="6" rx="2" /><rect x="3.5" y="15.5" width="7.5" height="5" rx="2" /><rect x="13" y="11.5" width="7.5" height="9" rx="2" />
  </svg>
);

const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export default function SaveSheet({ locale }: { locale: Locale }) {
  const c = copy(locale);
  const api = createApi('', locale);
  const [target, setTarget] = useState<Target | null>(null);
  const [guest, setGuest] = useState(false);
  const [favs, setFavs] = useState<Set<string>>(new Set());
  const [boards, setBoards] = useState<Board[] | null>(null);
  const [done, setDone] = useState<Done>({});
  const [favBusy, setFavBusy] = useState(false);
  const [creating, setCreating] = useState(false);
  const [title, setTitle] = useState('');
  const favsRef = useRef(favs);
  favsRef.current = favs;
  // Liked shops (hearts carrying data-like-shop): one tap likes or unlikes, no sheet; visitors get the sign-in sheet.
  const [liked, setLiked] = useState<Set<string>>(new Set());
  const likedRef = useRef(liked);
  likedRef.current = liked;
  const guestRef = useRef(guest);
  guestRef.current = guest;
  const sheet = useRef<HTMLDivElement>(null);

  // Paint every heart on the page from the favourites set (also for cards added later).
  const paint = () => {
    document.querySelectorAll<HTMLElement>('[data-save-offer]').forEach((b) => {
      b.setAttribute('aria-pressed', String(favsRef.current.has(b.dataset.saveOffer ?? '')));
    });
    document.querySelectorAll<HTMLElement>('[data-like-id]').forEach((b) => {
      b.setAttribute('aria-pressed', String(likedRef.current.has(b.dataset.likeId ?? '')));
    });
  };

  useEffect(() => {
    // Whether the visitor is signed in: known once the favourites answer (true = member, false = visitor).
    const member = api
      .request<{ data: { offer_ids: string[] } }>('/api/v1/boards/favorites')
      .then((r) => {
        setFavs(new Set(r.data.offer_ids));
        return true;
      })
      .catch(() => {
        setGuest(true);
        guestRef.current = true;
        return false;
      });
    api
      .request<{ data: { shop_ids: string[] } }>('/api/v1/shops-liked')
      .then((r) => setLiked(new Set(r.data.shop_ids)))
      .catch(() => {});
    const onClick = (e: MouseEvent) => {
      const shopBtn = (e.target as HTMLElement).closest<HTMLElement>('[data-like-shop]');
      if (shopBtn) {
        e.preventDefault();
        e.stopPropagation();
        const open = () => setTarget({ offerId: '', name: shopBtn.dataset.saveName ?? '', rect: shopBtn.getBoundingClientRect(), shop: true });
        if (guestRef.current) return open();
        void member.then((ok) => (ok ? likeShop() : open()));
        return;
      }
      function likeShop() {
        if (!shopBtn) return;
        const open = () => setTarget({ offerId: '', name: shopBtn.dataset.saveName ?? '', rect: shopBtn.getBoundingClientRect(), shop: true });
        const id = shopBtn.dataset.likeId ?? '';
        const was = likedRef.current.has(id);
        const flip = (on: boolean) =>
          setLiked((s) => {
            const n = new Set(s);
            if (on) n.add(id);
            else n.delete(id);
            return n;
          });
        flip(!was);
        api
          .request<{ data: { liked: boolean } }>('/api/v1/shops/' + encodeURIComponent(shopBtn.dataset.likeShop ?? '') + '/like', { method: 'POST' })
          .then((r) => flip(r.data.liked))
          .catch((err) => {
            flip(was);
            if ((err as { status?: number }).status === 401) {
              setGuest(true);
              open();
            }
          });
      }
      const b = (e.target as HTMLElement).closest<HTMLElement>('[data-save-offer]');
      if (!b) return;
      e.preventDefault();
      e.stopPropagation();
      setDone({});
      setCreating(false);
      setTarget({ offerId: b.dataset.saveOffer ?? '', name: b.dataset.saveName ?? '', rect: b.getBoundingClientRect() });
    };
    // The product page heart follows the chosen variant.
    const onVariant = (e: Event) => {
      const id = (e as CustomEvent<{ offerId: string }>).detail?.offerId;
      if (!id) return;
      document.querySelectorAll<HTMLElement>('[data-save-follow]').forEach((b) => (b.dataset.saveOffer = id));
      paint();
    };
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setTarget(null);
    let raf = 0;
    const mo = new MutationObserver(() => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(paint);
    });
    mo.observe(document.body, { childList: true, subtree: true });
    document.addEventListener('click', onClick, true);
    window.addEventListener('kora:variant', onVariant);
    window.addEventListener('keydown', onKey);
    return () => {
      mo.disconnect();
      document.removeEventListener('click', onClick, true);
      window.removeEventListener('kora:variant', onVariant);
      window.removeEventListener('keydown', onKey);
    };
  }, []);
  useEffect(paint, [favs, liked]);

  // Boards are loaded the first time the sheet opens for a signed-in member.
  useEffect(() => {
    if (!target || guest || boards) return;
    api
      .request<{ data: Board[] }>('/api/v1/boards')
      .then((r) => setBoards(r.data.filter((b) => !b.is_favorites && (b.role === 'owner' || b.role === 'editor'))))
      .catch(() => setBoards([]));
  }, [target, guest]);
  useEffect(() => {
    if (target) sheet.current?.querySelector<HTMLElement>('button, a')?.focus({ preventScroll: true });
  }, [target]);

  async function toggleFav() {
    if (!target || favBusy) return;
    setFavBusy(true);
    try {
      const r = await api.request<{ data: { favorite: boolean } }>('/api/v1/boards/favorites/toggle', {
        method: 'POST',
        headers: key(),
        body: JSON.stringify({ offer_id: target.offerId }),
      });
      setFavs((s) => {
        const n = new Set(s);
        if (r.data.favorite) n.add(target.offerId);
        else n.delete(target.offerId);
        return n;
      });
    } catch (e) {
      if ((e as { status?: number }).status === 401) setGuest(true);
    } finally {
      setFavBusy(false);
    }
  }

  async function pin(board: Board, retry = true): Promise<void> {
    if (!target) return;
    setDone((d) => ({ ...d, [board.id]: 'busy' }));
    try {
      const r = await api.request<{ data: { already_pinned?: boolean } }>('/api/v1/boards/' + board.id + '/pins', {
        method: 'POST',
        headers: key(),
        body: JSON.stringify({ version: board.version, offer_id: target.offerId, wished_quantity: 1 }),
      });
      setDone((d) => ({ ...d, [board.id]: r.data.already_pinned ? 'already' : 'added' }));
      setBoards((list) => list?.map((b) => (b.id === board.id ? { ...b, version: b.version + 1, pin_count: b.pin_count + (r.data.already_pinned ? 0 : 1) } : b)) ?? null);
    } catch (e) {
      // Someone changed the board meanwhile: reload its version once and try again.
      if (retry && (e as { status?: number }).status === 409) {
        const fresh = await api.request<{ data: { version: number } }>('/api/v1/boards/' + board.id).catch(() => null);
        if (fresh) return pin({ ...board, version: fresh.data.version }, false);
      }
      setDone((d) => ({ ...d, [board.id]: 'error' }));
    }
  }

  async function create(e: SyntheticEvent) {
    e.preventDefault();
    const t = title.trim();
    if (!t) return;
    try {
      const r = await api.request<{ data: Board }>('/api/v1/boards', {
        method: 'POST',
        headers: key(),
        body: JSON.stringify({ title: t, kind: 'collection', hide_gift_purchases: false }),
      });
      const board = { ...r.data, pin_count: 0, role: 'owner' };
      setBoards((list) => [board, ...(list ?? [])]);
      setTitle('');
      setCreating(false);
      await pin(board);
    } catch {
      /* the form stays open so the visitor can try again */
    }
  }

  if (!target) return null;
  const fav = favs.has(target.offerId);
  const next = typeof location !== 'undefined' ? encodeURIComponent(location.pathname + location.search) : '';
  // Desktop: a popover next to the heart. Phone: a sheet from the bottom.
  const wide = typeof window !== 'undefined' && window.innerWidth > 760;
  const r = target.rect;
  const below = r.bottom + 360 < window.innerHeight;
  const pos: CSSProperties = wide
    ? { position: 'fixed', left: Math.max(16, Math.min(r.right - 340, window.innerWidth - 356)), ...(below ? { top: r.bottom + 10 } : { bottom: window.innerHeight - r.top + 10 }) }
    : {};

  return (
    <>
      <div className="save-backdrop" onClick={() => setTarget(null)} />
      <div className={'save-sheet' + (wide ? ' pop' : '')} style={pos} ref={sheet} role="dialog" aria-label={c.saveTitle}>
        <header className="save-head">
          <span>
            <strong>{c.saveTitle}</strong>
            {target.name && <small>{target.name}</small>}
          </span>
          <button type="button" className="save-x" onClick={() => setTarget(null)} aria-label={c.close}><CloseIcon /></button>
        </header>
        {guest ? (
          <div className="save-guest">
            <span className="save-guest-ico" aria-hidden="true"><HeartIcon /></span>
            <p>{target.shop ? c.shopGuest : c.saveGuest}</p>
            <a className="btn btn-accent" href={'/' + locale + '/login?next=' + next}>{c.signIn}</a>
            <a className="save-link" href={'/' + locale + '/register?next=' + next}>{c.createAccount}</a>
          </div>
        ) : (
          <>
            <button type="button" className="save-fav" aria-pressed={fav} onClick={toggleFav} disabled={favBusy}>
              <span className="save-ico heart"><HeartIcon filled={fav} /></span>
              <span className="save-text"><strong>{c.favourites}</strong><small>{fav ? c.inFavourites : c.addFavouritesHint}</small></span>
              <span className="save-state">{favBusy ? <i className="spin" /> : fav ? <CheckIcon /> : <PlusIcon />}</span>
            </button>
            <p className="save-label">{c.inspirationBoards}</p>
            <ul className="save-boards">
              {boards === null && <li className="save-wait"><i className="spin" /></li>}
              {boards?.map((b) => {
                const s = done[b.id];
                return (
                  <li key={b.id}>
                    <button type="button" onClick={() => pin(b)} disabled={s === 'busy' || s === 'added' || s === 'already'} data-state={s}>
                      <span className="save-ico"><BoardIcon /></span>
                      <span className="save-text"><strong>{b.title}</strong><small>{s === 'added' ? c.pinned : s === 'already' ? c.alreadyPinned : s === 'error' ? c.pinFailed : c.boardCount.replace('{n}', String(b.pin_count))}</small></span>
                      <span className="save-state">{s === 'busy' ? <i className="spin" /> : s === 'added' || s === 'already' ? <CheckIcon /> : <PlusIcon />}</span>
                    </button>
                  </li>
                );
              })}
              {boards?.length === 0 && !creating && <li className="save-empty">{c.noBoardsYet}</li>}
            </ul>
            {creating ? (
              <form className="save-new" onSubmit={create}>
                <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder={c.boardNamePlaceholder} maxLength={150} autoFocus aria-label={c.boardNamePlaceholder} />
                <button type="submit" className="btn btn-accent" disabled={!title.trim()}>{c.create}</button>
              </form>
            ) : (
              <button type="button" className="save-add" onClick={() => setCreating(true)}><PlusIcon /> {c.newBoard}</button>
            )}
          </>
        )}
      </div>
    </>
  );
}
