import { useEffect, useState } from 'react';
import type { createApi } from '@kora/api-client';
import { money, type Locale } from '../lib/copy';
import { accountCopy } from '../lib/account-copy';
import { addToCart } from '../lib/cart';
import { AcLoading } from './AcModal';

// Liked products ("My favourites": every heart touched on a product) and followed shops (every heart touched on a
// shop), each with its own entry in the account menu. A product can be added to the cart or removed from the
// favourites at once; a shop can be unfollowed. Prices and availability are today's, from the catalogue.

type Api = ReturnType<typeof createApi>;
type Offer = { id: string; name: string; image: string | null; price: { amount: number }; availability: string; shop?: { name: string } };
type Pin = { id: string; offer: Offer | null; saved_name: string };
type Shop = { id: string; slug: string; name: string; avatar?: string | null; district?: string | null; city?: string | null };

// Kept between openings of the section: the second visit shows at once, then refreshes quietly.
let pinsCache: Pin[] | null = null;
let shopsCache: Shop[] | null = null;

const emptyIcons = {
  heart: <path d="M12 20.2s-7.4-4.5-9.1-9.2C1.7 7.6 4 4.4 7.3 4.4c2 0 3.6 1.1 4.7 2.8 1.1-1.7 2.7-2.8 4.7-2.8 3.3 0 5.6 3.2 4.4 6.6-1.7 4.7-9.1 9.2-9.1 9.2Z" />,
  store: <path d="M4 9h16l-1.2-4.5H5.2L4 9ZM5 9v10.5h14V9M9.5 19.5V14h5v5.5" />,
};
function Empty({ icon, title, text, href, cta }: { icon: keyof typeof emptyIcons; title: string; text: string; href: string; cta: string }) {
  return (
    <div className="ac-bempty ac-nobuy">
      <span className="ac-nobuy-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{emptyIcons[icon]}</svg></span>
      <h3>{title}</h3>
      <p>{text}</p>
      <a className="btn btn-dark btn-small" href={href}>{cta}</a>
    </div>
  );
}

export default function AccountFavorites({ locale, api, onNote, mode }: { locale: Locale; api: Api; onNote: (t: string) => void; mode: 'products' | 'shops' }) {
  const t = accountCopy(locale);
  const home = '/' + locale;
  const [pins, setPins] = useState<Pin[] | null>(pinsCache);
  const [shops, setShops] = useState<Shop[] | null>(shopsCache);
  const [busy, setBusy] = useState('');

  useEffect(() => {
    if (mode === 'products') {
      // The light list of favourite ids answers first: nothing liked = empty screen at once.
      const ids = api.request<{ data: { offer_ids: string[] } }>('/api/v1/boards/favorites');
      const boards = api.request<{ data: { id: string; is_favorites: boolean }[] }>('/api/v1/boards');
      ids.then(async (r) => {
        if (r.data.offer_ids.length === 0) return setPins((pinsCache = []));
        const fav = (await boards).data.find((b) => b.is_favorites);
        if (!fav) return setPins((pinsCache = []));
        setPins((pinsCache = (await api.request<{ data: { pins: Pin[] } }>('/api/v1/boards/' + fav.id)).data.pins));
      }).catch(() => setPins((p) => p ?? []));
    } else {
      Promise.all([api.request<{ data: { shop_ids: string[] } }>('/api/v1/shops-liked'), api.request<{ data: Shop[] }>('/api/v1/shops')])
        .then(([liked, all]) => setShops((shopsCache = all.data.filter((s) => liked.data.shop_ids.includes(s.id)))))
        .catch(() => setShops((x) => x ?? []));
    }
  }, [mode]);

  async function unfavorite(p: Pin) {
    if (!p.offer) return;
    setBusy(p.id);
    try {
      await api.request('/api/v1/boards/favorites/toggle', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify({ offer_id: p.offer.id }) });
      setPins((l) => (pinsCache = (l ?? []).filter((x) => x.id !== p.id)));
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function toCart(p: Pin) {
    if (!p.offer) return;
    setBusy('cart:' + p.id);
    try {
      await addToCart(locale, p.offer.id, 1, p.offer.name);
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }
  async function unfollow(s: Shop) {
    setBusy(s.id);
    try {
      await api.request('/api/v1/shops/' + s.slug + '/like', { method: 'POST', body: JSON.stringify({}) });
      setShops((l) => (shopsCache = (l ?? []).filter((x) => x.id !== s.id)));
    } catch {
      onNote(t.failed);
    } finally {
      setBusy('');
    }
  }

  if (mode === 'shops') {
    if (shops === null) return <AcLoading />;
    if (shops.length === 0) return <Empty icon="store" title={t.noShopsTitle} text={t.noLikedShops} href={home + '/shops'} cta={t.seeShops} />;
    return (
      <ul className="ac-fshops">
        {shops.map((s) => (
          <li key={s.id}>
            <a href={home + '/shops/' + s.slug}>
              <span className="ac-shop-av">{s.avatar ? <img src={s.avatar} alt="" loading="lazy" /> : s.name.charAt(0)}</span>
              <span><strong>{s.name}</strong><small>{[s.district, s.city].filter(Boolean).join(', ')}</small></span>
            </a>
            <button type="button" className="ac-follow" onClick={() => unfollow(s)} disabled={busy === s.id}>{t.unfollow}</button>
          </li>
        ))}
      </ul>
    );
  }

  if (pins === null) return <AcLoading kind="tiles" />;
  if (pins.length === 0) return <Empty icon="heart" title={t.noFavTitle} text={t.noFavorites} href={home + '/catalogue'} cta={t.seeCatalogue} />;
  return (
    <ul className="ac-pins ac-favs">
      {pins.map((p) => {
        const gone = !p.offer || p.offer.availability === 'unavailable';
        return (
          <li key={p.id} className={gone ? 'gone' : undefined}>
            <a className="ac-pin-img" href={p.offer ? home + '/products/' + p.offer.id : undefined}>{p.offer?.image && <img src={p.offer.image} alt="" loading="lazy" />}</a>
            <button type="button" className="ac-heart" onClick={() => unfavorite(p)} disabled={busy === p.id} aria-label={t.removeFavorite} title={t.removeFavorite}>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.2s-7.4-4.5-9.1-9.2C1.7 7.6 4 4.4 7.3 4.4c2 0 3.6 1.1 4.7 2.8 1.1-1.7 2.7-2.8 4.7-2.8 3.3 0 5.6 3.2 4.4 6.6-1.7 4.7-9.1 9.2-9.1 9.2Z" /></svg>
            </button>
            <div className="ac-pin-body">
              <a href={p.offer ? home + '/products/' + p.offer.id : undefined}>{p.offer?.name ?? p.saved_name}</a>
              {p.offer?.shop?.name && <small>{p.offer.shop.name}</small>}
              <span className="ac-pin-price">{gone ? <em>{t.unavailable}</em> : <b>{money(p.offer!.price.amount, locale)}</b>}</span>
            </div>
            {!gone && <button type="button" className="ac-fav-cart" onClick={() => toCart(p)} disabled={busy === 'cart:' + p.id}>{busy === 'cart:' + p.id && <i className="spin" />} {t.addToCart}</button>}
          </li>
        );
      })}
    </ul>
  );
}
