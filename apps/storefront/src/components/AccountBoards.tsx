import { useEffect, useState, type SyntheticEvent } from 'react';
import type { createApi } from '@kora/api-client';
import { money, type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';
import { loadCart } from '../lib/cart';
import AcModal, { AcLoading } from './AcModal';

// "My inspirations" (spec §11): the member's boards and gift lists as photo collages (favourites first), a new
// board in place, one board opened with its products (price now and when saved, availability), publish / make
// private, everything to the cart, remove a product, rename, delete. Favourites and followed shops have their own
// entries (AccountFavorites).

type Api = ReturnType<typeof createApi>;
type Offer = { id: string; name: string; image: string | null; price: { amount: number }; availability: string; shop?: { name: string } };
type Pin = { id: string; offer_id: string; saved_name: string; price_at_save: { amount: number }; offer: Offer | null };
type BoardRow = { id: string; title: string; kind: 'collection' | 'gift'; is_favorites: boolean; visibility: string; version: number; role: string | null; pin_count: number };
type Board = BoardRow & { pins: Pin[] };
const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export default function AccountBoards({ locale, api, onNote }: { locale: Locale; api: Api; onNote: (t: string) => void }) {
  const t = accountCopy(locale);
  const home = '/' + locale;
  const [boards, setBoards] = useState<Board[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [openId, setOpenId] = useState('');
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ title: '', kind: 'collection' as 'collection' | 'gift' });
  const [busy, setBusy] = useState('');
  const [renaming, setRenaming] = useState('');
  const [inviting, setInviting] = useState(false);
  const [invite, setInvite] = useState({ email: '', role: 'viewer' as 'viewer' | 'editor' });
  const [link, setLink] = useState('');

  async function load() {
    try {
      const rows = (await api.request<{ data: BoardRow[] }>('/api/v1/boards')).data;
      const full = await Promise.all(rows.slice(0, 30).map((b) => api.request<{ data: Board }>('/api/v1/boards/' + b.id).then((r) => ({ ...b, ...r.data, pin_count: b.pin_count })).catch(() => ({ ...b, pins: [] }))));
      setBoards(full.filter((b) => !b.is_favorites));
    } catch {
      setBoards([]);
      setFailed(true);
    }
  }
  useEffect(() => {
    load();
  }, []);

  const board = boards?.find((b) => b.id === openId);
  useEffect(() => {
    const onBack = (e: Event) => {
      if (openId) { setOpenId(''); e.preventDefault(); } else if (creating) { setCreating(false); e.preventDefault(); }
    };
    addEventListener('kora:ac-back', onBack);
    return () => removeEventListener('kora:ac-back', onBack);
  }, [openId, creating]);
  const replace = (b: Partial<Board> & { id: string }) => setBoards((list) => (list ?? []).map((x) => (x.id === b.id ? { ...x, ...b } : x)));
  const name = (b: Board) => (b.is_favorites ? t.favorites : b.title);

  async function create(e: SyntheticEvent) {
    e.preventDefault();
    setBusy('create');
    try {
      const r = await api.request<{ data: Board }>('/api/v1/boards', { method: 'POST', headers: key(), body: JSON.stringify({ title: form.title, description: '', kind: form.kind, gift_date: null, hide_gift_purchases: false }) });
      setBoards((list) => [...(list ?? []), { ...r.data, pins: r.data.pins ?? [], pin_count: 0, is_favorites: false }]);
      setCreating(false);
      setForm({ title: '', kind: 'collection' });
      setOpenId(r.data.id);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function visibility(b: Board, value: 'private' | 'public') {
    setBusy('vis');
    try {
      const r = await api.request<{ data: Board }>('/api/v1/boards/' + b.id + '/visibility', { method: 'POST', headers: key(), body: JSON.stringify({ version: b.version, visibility: value, reviewed: true }) });
      replace({ id: b.id, visibility: value, version: r.data?.version ?? b.version + 1 });
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function removePin(b: Board, p: Pin) {
    setBusy(p.id);
    try {
      const r = await api.request<{ data: { version?: number } }>('/api/v1/boards/' + b.id + '/pins/' + p.id, { method: 'DELETE', headers: key(), body: JSON.stringify({ version: b.version }) });
      replace({ id: b.id, pins: b.pins.filter((x) => x.id !== p.id), pin_count: b.pin_count - 1, version: r.data?.version ?? b.version + 1 });
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function toCart(b: Board) {
    setBusy('cart');
    try {
      const cart = await loadCart(locale);
      const ids = b.pins.filter((p) => p.offer && p.offer.availability !== 'unavailable').map((p) => p.id);
      await api.request('/api/v1/boards/' + b.id + '/to-cart', { method: 'POST', headers: key(), body: JSON.stringify({ version: b.version, cart_version: cart.version, pin_ids: ids }) });
      const fresh = await loadCart(locale);
      window.dispatchEvent(new CustomEvent('kora:cart', { detail: fresh }));
      window.dispatchEvent(new CustomEvent('kora:open-cart'));
      onNote(t.addedToCart);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  // A private link to the board (30 days, revocable), shared through the phone's share sheet or copied.
  async function shareLink(b: Board) {
    setBusy('share');
    try {
      const r = await api.request<{ data: { url: string; version?: number } }>('/api/v1/boards/' + b.id + '/share', { method: 'POST', headers: key(), body: JSON.stringify({ version: b.version, expires_in_days: 30, reviewed: true }) });
      const parts = r.data.url.split('/');
      const url = location.origin + home + '/inspirations/partage/' + parts[parts.length - 2] + '/' + parts[parts.length - 1];
      replace({ id: b.id, version: r.data.version ?? b.version + 1, visibility: 'unlisted' });
      if (navigator.share) await navigator.share({ title: b.title, url }).catch(() => {});
      else await navigator.clipboard?.writeText(url);
      onNote(t.linkCopied);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function sendInvite(b: Board) {
    setBusy('invite');
    try {
      const r = await api.request<{ data: { url: string } }>('/api/v1/boards/' + b.id + '/invitations', { method: 'POST', headers: key(), body: JSON.stringify({ version: b.version, email: invite.email, role: invite.role, expires_in_days: 14 }) });
      const token = r.data.url.split('/').pop() ?? '';
      setLink(location.origin + home + '/inspirations/invitation/' + token);
      replace({ id: b.id, version: b.version + 1 });
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function rename(b: Board, title: string) {
    setBusy('rename');
    try {
      const r = await api.request<{ data: Board }>('/api/v1/boards/' + b.id, { method: 'PUT', headers: key(), body: JSON.stringify({ version: b.version, title, description: '', cover_pin_id: null }) });
      replace({ id: b.id, title, version: r.data?.version ?? b.version + 1 });
      setRenaming('');
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function archive(b: Board) {
    if (!confirm(t.confirmDelete)) return;
    setBusy('archive');
    try {
      await api.request('/api/v1/boards/' + b.id + '/archive', { method: 'POST', headers: key(), body: JSON.stringify({ version: b.version }) });
      setBoards((list) => (list ?? []).filter((x) => x.id !== b.id));
      setOpenId('');
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }

  if (boards === null) return <AcLoading kind="tiles" />;

  // One board, opened
  if (board) {
    const owner = board.role === 'owner';
    return (
      <div className="ac-board">
        <button type="button" className="ac-sub-back" onClick={() => setOpenId('')}>‹ {t.tabs.inspirations}</button>
        <header className="ac-board-head">
          {renaming === board.id ? (
            <form className="ac-inline" onSubmit={(e) => { e.preventDefault(); rename(board, (new FormData(e.currentTarget).get('title') as string).trim()); }}>
              <input name="title" defaultValue={board.title} maxLength={150} required autoFocus />
              <button type="submit" className="btn btn-dark btn-small" disabled={busy === 'rename'}>{t.save}</button>
            </form>
          ) : (
            <div>
              <h3>{name(board)}</h3>
              <p>{t.pins(board.pins.length)} · {board.kind === 'gift' ? t.kindGift : t.kindCollection} · {board.visibility === 'public' ? t.public : t.private}</p>
            </div>
          )}
          <div className="ac-board-actions">
            {board.pins.length > 0 && <button type="button" className="btn btn-accent btn-small" onClick={() => toCart(board)} disabled={busy === 'cart'}>{busy === 'cart' && <i className="spin" />} {t.allToCart}</button>}
            {owner && !board.is_favorites && renaming !== board.id && <button type="button" className="btn btn-line btn-small" onClick={() => setRenaming(board.id)}>{t.rename}</button>}
            {owner && !board.is_favorites && <button type="button" className="btn btn-line btn-small" onClick={() => shareLink(board)} disabled={busy === 'share'}>{t.shareLink}</button>}
            {owner && !board.is_favorites && <button type="button" className="btn btn-line btn-small" onClick={() => { setInviting(true); setLink(''); }}>{t.invite}</button>}
          </div>
        </header>
        {owner && !board.is_favorites && (
          <div className="ac-vis">
            <div>
              <strong>{board.visibility === 'public' ? t.public : t.private}</strong>
              <small>{board.visibility === 'public' ? t.publicNote : t.privateNote}</small>
            </div>
            {board.visibility === 'public' && <a href={home + '/inspirations/' + board.id}>{t.seePublic}</a>}
            <button type="button" className="ac-switch" role="switch" aria-checked={board.visibility === 'public'} aria-label={t.makePublic} disabled={busy === 'vis'} onClick={() => visibility(board, board.visibility === 'public' ? 'private' : 'public')}><i /></button>
          </div>
        )}
        {board.pins.length === 0 ? (
          <p className="ac-empty-text">{t.emptyBoard}</p>
        ) : (
          <ul className="ac-pins">
            {board.pins.map((p) => {
              const now = p.offer?.price.amount ?? null;
              const gone = !p.offer || p.offer.availability === 'unavailable';
              return (
                <li key={p.id} className={gone ? 'gone' : undefined}>
                  <a className="ac-pin-img" href={p.offer ? home + '/products/' + p.offer.id : undefined}>{p.offer?.image && <img src={p.offer.image} alt="" loading="lazy" />}</a>
                  <div className="ac-pin-body">
                    <a href={p.offer ? home + '/products/' + p.offer.id : undefined}>{p.offer?.name ?? p.saved_name}</a>
                    {p.offer?.shop?.name && <small>{p.offer.shop.name}</small>}
                    <span className="ac-pin-price">
                      {gone ? <em>{t.unavailable}</em> : <b>{money(now ?? 0, locale)}</b>}
                      {now !== null && now !== p.price_at_save.amount && p.price_at_save.amount > 0 && <small>{t.savedAt(money(p.price_at_save.amount, locale))}</small>}
                    </span>
                  </div>
                  <button type="button" className="ac-pin-remove" onClick={() => removePin(board, p)} disabled={busy === p.id} aria-label={t.removePin} title={t.removePin}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
        {inviting && (
          <AcModal title={t.invite} onClose={() => setInviting(false)}>
            {link ? (
              <div className="acm-form">
                <p className="acm-sub">{t.inviteReady}</p>
                <input className="ac-link-box" readOnly value={link} onFocus={(e) => e.currentTarget.select()} />
                <button type="button" className="btn btn-accent ac-wide" onClick={() => { navigator.clipboard?.writeText(link); onNote(t.linkCopied); }}>{t.copy}</button>
              </div>
            ) : (
              <form className="acm-form" onSubmit={(e) => { e.preventDefault(); sendInvite(board); }}>
                <label className="ac-field"><span>{t.inviteEmail}</span><input type="email" required value={invite.email} onChange={(e) => setInvite({ ...invite, email: e.target.value })} /></label>
                <div className="ac-kinds2" role="radiogroup">
                  {(['viewer', 'editor'] as const).map((r) => (
                    <label key={r} data-on={invite.role === r}>
                      <input type="radio" name="role" checked={invite.role === r} onChange={() => setInvite({ ...invite, role: r })} />
                      <strong>{r === 'viewer' ? t.roleViewer : t.roleEditor}</strong>
                      <small>{r === 'viewer' ? t.roleViewerText : t.roleEditorText}</small>
                    </label>
                  ))}
                </div>
                <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'invite'}>{busy === 'invite' && <i className="spin" />} {t.inviteSend}</button>
              </form>
            )}
          </AcModal>
        )}
        {owner && !board.is_favorites && <button type="button" className="ac-danger" onClick={() => archive(board)} disabled={busy === 'archive'}>{t.deleteBoard}</button>}
      </div>
    );
  }

  // The new-board window (title + type), used from the empty screen and from the "+" card.
  const newBoard = creating && (
    <AcModal title={t.newBoard} onClose={() => setCreating(false)}>
      <form className="acm-form" onSubmit={create}>
        <label className="ac-field"><span>{t.boardTitle}</span><input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder={t.boardTitleHint} maxLength={150} required autoFocus /></label>
        <div className="ac-field">
          <span>{t.boardKind}</span>
          <div className="ac-kinds2" role="radiogroup" aria-label={t.boardKind}>
            {(['collection', 'gift'] as const).map((k) => (
              <label key={k} data-on={form.kind === k}>
                <input type="radio" name="kind" checked={form.kind === k} onChange={() => setForm({ ...form, kind: k })} />
                <strong>{k === 'gift' ? t.kindGift : t.kindCollection}</strong>
                <small>{k === 'gift' ? t.kindGiftText : t.kindCollectionText}</small>
              </label>
            ))}
          </div>
        </div>
        <button type="submit" className="btn btn-accent ac-wide" disabled={busy === 'create'}>{busy === 'create' && <i className="spin" />} {t.create}</button>
      </form>
    </AcModal>
  );

  // No board yet: one calm invitation (Pinterest-like), not an empty grid.
  if (boards.length === 0) {
    return (
      <div className="ac-boards">
        {failed && <p className="ac-empty-text">{t.loadFailed}</p>}
        <div className="ac-bempty ac-nobuy">
          <span className="ac-nobuy-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M3.5 3.5h7.5v10H3.5zM13 3.5h7.5v6H13zM3.5 15.5h7.5v5H3.5zM13 11.5h7.5v9H13z" /></svg></span>
          <h3>{t.noBoards}</h3>
          <p>{t.noBoardsText}</p>
          <button type="button" className="btn btn-accent btn-small" onClick={() => setCreating(true)}>{t.createBoard}</button>
        </div>
        {newBoard}
      </div>
    );
  }

  return (
    <div className="ac-boards">
      <p className="ac-intro">{t.boardsIntro}</p>
      {failed && <p className="ac-empty-text">{t.loadFailed}</p>}
      <div className="ac-bgrid">
        {boards.map((b) => {
          const pics = b.pins.map((p) => p.offer?.image).filter(Boolean) as string[];
          return (
            <button key={b.id} type="button" className="ac-bcard" onClick={() => setOpenId(b.id)}>
              <span className="ac-collage">
                <span className="big">{pics[0] && <img src={pics[0]} alt="" loading="lazy" />}</span>
                <span>{pics[1] && <img src={pics[1]} alt="" loading="lazy" />}</span>
                <span>{pics[2] && <img src={pics[2]} alt="" loading="lazy" />}</span>
                {b.kind === 'gift' && <em>{t.kindGift}</em>}
              </span>
              <strong>{name(b)}</strong>
              <small>{t.pins(b.pin_count)} · {b.visibility === 'public' ? t.public : t.private}</small>
            </button>
          );
        })}
        {(
          <button type="button" className="ac-bcard ac-badd" onClick={() => setCreating(true)}>
            <span className="ac-collage"><span className="big plus">+</span></span>
            <strong>{t.newBoard}</strong>
            <small>{t.kindCollection} · {t.kindGift}</small>
          </button>
        )}
      </div>
      {newBoard}

    </div>
  );
}
