import { useEffect, useRef, useState } from 'react';
import { createApi } from '@kora/api-client';
import { copy, money, tone, type Locale } from '../lib/copy';
import { count, loadCart, setQuantity, type Cart } from '../lib/cart';
import { BagIcon, CloseIcon, MenuIcon, SearchIcon, UserIcon } from './icons';

type Theme = 'system' | 'light' | 'dark';
type Link = { href: string; label: string };

/** Header tools: search, cart drawer, mobile menu, language and theme. */
export default function Shell({ locale, links, query = '' }: { locale: Locale; links: Link[]; query?: string }) {
  const c = copy(locale);
  const [cart, setCart] = useState<Cart | null>(null);
  const [drawer, setDrawer] = useState(false);
  const [menu, setMenu] = useState(false);
  const [search, setSearch] = useState(false);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState('');
  const [theme, setTheme] = useState<Theme>('system');
  const searchInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    loadCart(locale).then(setCart).catch(() => {});
    const onCart = (e: Event) => setCart((e as CustomEvent<Cart>).detail);
    const onAdded = () => {
      setToast(c.added);
      window.setTimeout(() => setToast(''), 2200);
    };
    window.addEventListener('kora:cart', onCart);
    window.addEventListener('kora:cart-added', onAdded);
    const t = document.documentElement.dataset.theme as Theme;
    if (['system', 'light', 'dark'].includes(t)) setTheme(t);
    const header = document.querySelector('.site-header');
    const onScroll = () => header?.setAttribute('data-scrolled', String(window.scrollY > 8));
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      window.removeEventListener('kora:cart', onCart);
      window.removeEventListener('kora:cart-added', onAdded);
      window.removeEventListener('scroll', onScroll);
    };
  }, []);
  useEffect(() => {
    if (search) searchInput.current?.focus();
  }, [search]);
  useEffect(() => {
    const close = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setDrawer(false);
        setMenu(false);
        setSearch(false);
      }
    };
    window.addEventListener('keydown', close);
    document.body.style.overflow = drawer ? 'hidden' : '';
    return () => window.removeEventListener('keydown', close);
  }, [drawer]);

  function applyTheme(value: Theme) {
    setTheme(value);
    document.documentElement.dataset.theme = value;
    try {
      localStorage.setItem('kora.theme', value);
    } catch {}
    void createApi('', locale).preferences({ locale, theme: value }).catch(() => {});
  }
  function language(value: Locale) {
    document.cookie = 'kora_locale=' + value + '; Path=/; SameSite=Lax; Max-Age=31536000';
    const url = new URL(window.location.href);
    url.pathname = url.pathname.replace(/^\/(fr|en)(?=\/|$)/, '/' + value);
    window.location.assign(url.pathname + url.search);
  }
  async function change(lineId: string, quantity: number) {
    if (!cart || busy) return;
    const line = cart.lines.find((l) => l.id === lineId);
    if (!line) return;
    setBusy(true);
    try {
      setCart(await setQuantity(locale, cart, line, quantity));
    } catch {
      setCart(await loadCart(locale).catch(() => cart));
    } finally {
      setBusy(false);
    }
  }
  const prefs = (
    <div className="prefs">
      <label className="sr-only" htmlFor={'lang-' + locale}>Langue / Language</label>
      <select id={'lang-' + locale} value={locale} onChange={(e) => language(e.target.value as Locale)}>
        <option value="fr">FR</option>
        <option value="en">EN</option>
      </select>
      <label className="sr-only" htmlFor="theme-pick">Thème</label>
      <select id="theme-pick" value={theme} onChange={(e) => applyTheme(e.target.value as Theme)}>
        <option value="system">{locale === 'fr' ? 'Auto' : 'Auto'}</option>
        <option value="light">{locale === 'fr' ? 'Clair' : 'Light'}</option>
        <option value="dark">{locale === 'fr' ? 'Sombre' : 'Dark'}</option>
      </select>
    </div>
  );
  const n = count(cart);

  return (
    <>
      <div className="header-tools">
        <button type="button" className="tool" aria-expanded={search} aria-controls="site-search" onClick={() => setSearch(!search)}>
          <SearchIcon />
          <span className="label">{c.search}</span>
        </button>
        <a className="tool" href={'/' + locale + '/account'} aria-label={c.account}>
          <UserIcon />
        </a>
        <button type="button" className="tool" onClick={() => setDrawer(true)} aria-haspopup="dialog">
          <BagIcon />
          <span className="label">{c.cart}</span>
          <span className="cart-count" aria-label={n + ' ' + c.cart}>{n}</span>
        </button>
        {prefs}
        <button type="button" className="tool menu-toggle" aria-expanded={menu} aria-controls="mobile-nav" onClick={() => setMenu(!menu)} aria-label={c.menu}>
          {menu ? <CloseIcon /> : <MenuIcon />}
        </button>
      </div>
      <div className="search-bar header-span" id="site-search" data-open={search}>
        <form action={'/' + locale + '/catalogue'} role="search">
          <label className="sr-only" htmlFor="q-top">{c.search}</label>
          <input id="q-top" ref={searchInput} type="search" name="q" defaultValue={query} placeholder={c.searchPlaceholder} tabIndex={search ? 0 : -1} />
          <button className="btn btn-dark" type="submit" tabIndex={search ? 0 : -1}>{c.search}</button>
        </form>
      </div>
      <nav className="mobile-nav header-span" id="mobile-nav" data-open={menu} aria-label={c.menu}>
        {links.map((l) => (
          <a key={l.href} href={l.href}>{l.label}</a>
        ))}
        {prefs}
      </nav>
      <div className="drawer-backdrop" data-open={drawer} hidden={!drawer} onClick={() => setDrawer(false)} />
      <aside className="drawer" data-open={drawer} role="dialog" aria-modal="true" aria-label={c.cartTitle} aria-hidden={!drawer}>
        <header>
          <h2>{c.cartTitle}</h2>
          <button type="button" className="icon-round" onClick={() => setDrawer(false)} aria-label={c.close}>
            <CloseIcon />
          </button>
        </header>
        <div className="drawer-lines" aria-busy={busy}>
          {!cart || cart.lines.length === 0 ? (
            <div className="empty" style={{ marginTop: 20 }}>
              <h3>{c.emptyCart}</h3>
              <p className="muted">{c.emptyCartText}</p>
              <a className="btn btn-dark btn-small" href={'/' + locale + '/catalogue'}>{c.catalogue}</a>
            </div>
          ) : (
            cart.lines.map((l) => (
              <div className="line" key={l.id}>
                <div className={'line-img ' + tone(l.offer?.category ?? l.offer_id)}>
                  {l.offer?.image && <img src={l.offer.image} alt="" loading="lazy" />}
                </div>
                <div>
                  <h3><a href={'/' + locale + '/products/' + l.offer_id}>{l.offer?.name ?? '—'}</a></h3>
                  <small>{l.offer?.shop.name}</small>
                  {Object.keys(l.changes ?? {}).length > 0 && <small style={{ display: 'block', color: 'var(--accent)' }}>{c.priceChanged}</small>}
                  <div className="line-qty">
                    <button type="button" aria-label={c.decrease} onClick={() => change(l.id, l.quantity - 1)} disabled={busy}>−</button>
                    <output aria-label={c.quantity}>{l.quantity}</output>
                    <button type="button" aria-label={c.increase} onClick={() => change(l.id, l.quantity + 1)} disabled={busy}>+</button>
                  </div>
                </div>
                <div className="line-total">
                  {l.current_line_total ? money(l.current_line_total, locale) : c.unavailable}
                  <button type="button" onClick={() => change(l.id, 0)} disabled={busy}>{c.remove}</button>
                </div>
              </div>
            ))
          )}
        </div>
        {cart && cart.lines.length > 0 && (
          <footer>
            <div className="subtotal">
              <span>{c.subtotal}</span>
              <span>{cart.selected_subtotal ? money(cart.selected_subtotal, locale) : '—'}</span>
            </div>
            <small className="muted">{c.deliveryLater}</small>
            <a className="btn btn-accent" href={'/' + locale + '/cart'}>{c.checkout}</a>
            <button type="button" className="btn btn-line" onClick={() => setDrawer(false)}>{c.continueShopping}</button>
          </footer>
        )}
      </aside>
      <div className="toast" role="status" aria-live="polite" data-show={toast !== ''}>{toast}</div>
    </>
  );
}
